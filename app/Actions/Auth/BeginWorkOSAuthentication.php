<?php

namespace App\Actions\Auth;

use App\Contracts\WorkOSAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BeginWorkOSAuthentication
{
    public const INTENT_LINK = 'link';

    public const INTENT_LOGIN = 'login';

    public const INTENT_CONFIRM = 'confirm';

    public function __construct(private WorkOSAuthenticator $workOS) {}

    public function handle(Request $request, string $intent): RedirectResponse
    {
        if ($intent === self::INTENT_CONFIRM && ! is_string($request->user()?->workos_user_id)) {
            return $this->failure($intent, __('This account does not have a linked social identity to confirm.'));
        }

        $state = Str::random(64);

        try {
            $authorizationUrl = $this->workOS->authorizationUrl(
                redirectUri: $this->redirectUri(),
                state: $state,
                maxAge: $intent === self::INTENT_CONFIRM ? 0 : null,
            );
        } catch (Throwable $exception) {
            Log::warning('WorkOS authentication could not be started.', [
                'intent' => $intent,
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return $this->failure($intent, __('Social sign-in is temporarily unavailable. Please try again.'));
        }

        $request->session()->put('workos.authentication', [
            'state_hash' => hash('sha256', $state),
            'intent' => $intent,
            'user_id' => $intent !== self::INTENT_LOGIN ? $request->user()?->getAuthIdentifier() : null,
            'intended' => $this->safeIntendedPath($request, $intent),
            'started_at' => now()->getTimestamp(),
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        return redirect()->away($authorizationUrl);
    }

    private function redirectUri(): string
    {
        $redirectUri = config('workos.redirect_uri');

        if (! is_string($redirectUri) || $redirectUri === '') {
            throw new RuntimeException('The WorkOS redirect URI is not configured.');
        }

        return $redirectUri;
    }

    private function safeIntendedPath(Request $request, string $intent): string
    {
        $intended = $request->session()->get('url.intended');

        if (! is_string($intended) || $intended === '') {
            return $intent === self::INTENT_CONFIRM
                ? route('security.edit', absolute: false)
                : route('dashboard', absolute: false);
        }

        if (str_starts_with($intended, '/') && ! str_starts_with($intended, '//')) {
            return $intended;
        }

        $intendedParts = parse_url($intended);
        $applicationParts = parse_url(config('app.url'));

        if (($intendedParts['host'] ?? null) !== ($applicationParts['host'] ?? null)
            || ($intendedParts['scheme'] ?? null) !== ($applicationParts['scheme'] ?? null)) {
            return $intent === self::INTENT_CONFIRM
                ? route('security.edit', absolute: false)
                : route('dashboard', absolute: false);
        }

        return ($intendedParts['path'] ?? '/').(isset($intendedParts['query']) ? '?'.$intendedParts['query'] : '');
    }

    private function failure(string $intent, string $message): RedirectResponse
    {
        $route = match ($intent) {
            self::INTENT_LINK => 'security.edit',
            self::INTENT_CONFIRM => 'password.confirm',
            default => 'login',
        };

        return redirect()->route($route)->withErrors(['workos' => $message]);
    }
}
