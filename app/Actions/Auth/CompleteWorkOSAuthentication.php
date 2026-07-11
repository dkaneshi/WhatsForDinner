<?php

namespace App\Actions\Auth;

use App\Contracts\WorkOSAuthenticator;
use App\Data\WorkOSIdentity;
use App\Exceptions\WorkOSAuthenticationException;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Throwable;

class CompleteWorkOSAuthentication
{
    public function __construct(
        private WorkOSAuthenticator $workOS,
        private ResolveWorkOSUser $resolveUser,
    ) {}

    public function handle(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('workos.authentication');

        if (! $this->validState($request, $pending)) {
            Log::warning('WorkOS authentication rejected invalid state.', [
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return $this->failure(BeginWorkOSAuthentication::INTENT_LOGIN, __('Your social sign-in session expired or was invalid. Please try again.'));
        }

        $intent = $pending['intent'];

        if ($intent !== BeginWorkOSAuthentication::INTENT_LOGIN
            && $request->user()?->getAuthIdentifier() !== ($pending['user_id'] ?? null)) {
            Log::warning('WorkOS authentication rejected an authenticated user mismatch.', [
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return $this->failure($intent, __('Sign in again before continuing.'));
        }

        if ($request->filled('error')) {
            Log::notice('WorkOS authentication was cancelled or rejected.', [
                'intent' => $intent,
                'user_id' => $pending['user_id'] ?? null,
                'error' => $request->string('error')->limit(100)->toString(),
            ]);

            return $this->failure($intent, __('Social sign-in was cancelled or could not be completed.'));
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->failure($intent, __('WorkOS did not return an authorization code. Please try again.'));
        }

        try {
            $identity = $this->workOS->authenticate($code, $request->ip(), $request->userAgent());
        } catch (Throwable $exception) {
            Log::warning('WorkOS authorization code exchange failed.', [
                'intent' => $intent,
                'user_id' => $pending['user_id'] ?? null,
            ]);

            return $this->failure($intent, __('Social sign-in is temporarily unavailable. Please try again.'));
        }

        if ($intent === BeginWorkOSAuthentication::INTENT_CONFIRM) {
            return $this->confirm($request, $identity, $pending);
        }

        try {
            $resolution = $this->resolveUser->handle($identity, $intent, $pending['user_id'] ?? null);
        } catch (WorkOSAuthenticationException $exception) {
            Log::notice('WorkOS authentication was rejected.', [
                'intent' => $intent,
                'workos_user_id' => $identity->id,
                'user_id' => $pending['user_id'] ?? null,
            ]);

            return $this->failure($intent, $exception->safeMessage);
        }

        if ($resolution->linked) {
            Log::info('WorkOS identity linked.', [
                'user_id' => $resolution->user->getKey(),
                'workos_user_id' => $identity->id,
            ]);

            return redirect()->route('security.edit')->with('status', __('Social sign-in is now linked to your account.'));
        }

        return $this->authenticate($request, $resolution->user, $pending['intended'], $resolution->created);
    }

    /**
     * @param  array<string, mixed>|mixed  $pending
     */
    private function validState(Request $request, mixed $pending): bool
    {
        $state = $request->query('state');

        return is_array($pending)
            && is_string($state)
            && $state !== ''
            && isset($pending['state_hash'], $pending['intent'], $pending['intended'], $pending['started_at'], $pending['expires_at'])
            && is_string($pending['state_hash'])
            && is_string($pending['intended'])
            && in_array($pending['intent'], [BeginWorkOSAuthentication::INTENT_LOGIN, BeginWorkOSAuthentication::INTENT_LINK, BeginWorkOSAuthentication::INTENT_CONFIRM], true)
            && is_int($pending['started_at'])
            && is_int($pending['expires_at'])
            && $pending['expires_at'] >= now()->getTimestamp()
            && hash_equals($pending['state_hash'], hash('sha256', $state));
    }

    private function authenticate(Request $request, User $user, string $intended, bool $created): RedirectResponse
    {
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
                'url.intended' => $intended,
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        Log::info($created ? 'WorkOS social registration succeeded.' : 'WorkOS social sign-in succeeded.', [
            'user_id' => $user->getKey(),
            'workos_user_id' => $user->workos_user_id,
        ]);

        return redirect()->to($intended);
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function confirm(Request $request, WorkOSIdentity $identity, array $pending): RedirectResponse
    {
        $user = $request->user();
        $freshAfter = $pending['started_at'] - 5;

        if (! $user
            || ! $identity->emailVerified
            || ! is_string($user->workos_user_id)
            || ! hash_equals($user->workos_user_id, $identity->id)
            || $identity->authenticatedAt === null
            || $identity->authenticatedAt < $freshAfter
            || $identity->authenticatedAt > now()->addMinute()->getTimestamp()) {
            Log::notice('WorkOS identity confirmation was rejected.', [
                'user_id' => $user?->getAuthIdentifier(),
                'workos_user_id' => $identity->id,
            ]);

            return $this->failure(BeginWorkOSAuthentication::INTENT_CONFIRM, __('WorkOS could not freshly confirm the social identity linked to this account.'));
        }

        $request->session()->passwordConfirmed();
        $request->session()->forget('url.intended');

        Log::info('WorkOS identity confirmation succeeded.', [
            'user_id' => $user->getAuthIdentifier(),
            'workos_user_id' => $identity->id,
        ]);

        return redirect()->to($pending['intended']);
    }

    private function failure(string $intent, string $message): RedirectResponse
    {
        $route = match (true) {
            $intent === BeginWorkOSAuthentication::INTENT_CONFIRM && Auth::check() => 'password.confirm',
            $intent === BeginWorkOSAuthentication::INTENT_LINK && Auth::check() => 'security.edit',
            default => 'login',
        };

        return redirect()->route($route)->withErrors(['workos' => $message]);
    }
}
