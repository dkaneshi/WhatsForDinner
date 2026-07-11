<?php

namespace App\Actions\Auth;

use App\Data\WorkOSIdentity;
use App\Data\WorkOSResolution;
use App\Exceptions\WorkOSAuthenticationException;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResolveWorkOSUser
{
    public function handle(WorkOSIdentity $identity, string $intent, ?int $linkingUserId): WorkOSResolution
    {
        if (! $identity->emailVerified) {
            throw new WorkOSAuthenticationException(__('WorkOS could not verify your email address. Please verify it before continuing.'));
        }

        if ($intent === BeginWorkOSAuthentication::INTENT_LINK) {
            return $this->link($identity, $linkingUserId);
        }

        return $this->resolveLogin($identity);
    }

    private function link(WorkOSIdentity $identity, ?int $linkingUserId): WorkOSResolution
    {
        return DB::transaction(function () use ($identity, $linkingUserId): WorkOSResolution {
            $user = User::query()->lockForUpdate()->find($linkingUserId);

            if (! $user || ! $user->hasVerifiedEmail()) {
                throw new WorkOSAuthenticationException(__('Sign in again and verify your email before linking social sign-in.'));
            }

            if (! hash_equals($this->normalizeEmail($user->email), $this->normalizeEmail($identity->email))) {
                throw new WorkOSAuthenticationException(__('The social account email must match your verified WhatsForDinner email.'));
            }

            if ($user->workos_user_id !== null && ! hash_equals($user->workos_user_id, $identity->id)) {
                throw new WorkOSAuthenticationException(__('Your account is already linked to a different social identity.'));
            }

            $owner = User::query()->where('workos_user_id', $identity->id)->lockForUpdate()->first();

            if ($owner && ! $owner->is($user)) {
                throw new WorkOSAuthenticationException(__('That social account is already linked to another WhatsForDinner account.'));
            }

            $user->forceFill(['workos_user_id' => $identity->id])->save();

            return new WorkOSResolution($user, linked: true);
        });
    }

    private function resolveLogin(WorkOSIdentity $identity): WorkOSResolution
    {
        if ($user = User::query()->where('workos_user_id', $identity->id)->first()) {
            return new WorkOSResolution($user, linked: false);
        }

        try {
            return DB::transaction(function () use ($identity): WorkOSResolution {
                if ($this->findByEmail($identity->email)) {
                    throw new WorkOSAuthenticationException(__('An account already exists for this email. Sign in with your existing method, then link social sign-in from Security settings.'));
                }

                $user = new User;
                $user->forceFill([
                    'name' => $this->nameFor($identity),
                    'email' => $this->normalizeEmail($identity->email),
                    'email_verified_at' => now(),
                    'workos_user_id' => $identity->id,
                    'password' => Hash::make(Str::random(64)),
                ])->save();

                return new WorkOSResolution($user, linked: false, created: true);
            });
        } catch (QueryException $exception) {
            if ($user = User::query()->where('workos_user_id', $identity->id)->first()) {
                return new WorkOSResolution($user, linked: false);
            }

            if ($this->findByEmail($identity->email)) {
                throw new WorkOSAuthenticationException(__('An account already exists for this email. Sign in with your existing method, then link social sign-in from Security settings.'));
            }

            throw $exception;
        }
    }

    private function findByEmail(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$this->normalizeEmail($email)])
            ->first();
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function nameFor(WorkOSIdentity $identity): string
    {
        $name = trim($identity->name ?? '');

        return Str::limit($name !== '' ? $name : Str::before($identity->email, '@'), 255, '');
    }
}
