<?php

namespace App\Actions\Families;

use App\Models\FamilyInvitation;
use App\Models\User;
use App\Notifications\FamilyInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ResendFamilyInvitation
{
    public function __construct(private IssueFamilyInvitationToken $issueToken) {}

    /**
     * Replace an invitation token and restart its expiration window.
     */
    public function execute(User $head, FamilyInvitation $invitation): FamilyInvitation
    {
        Gate::forUser($head)->authorize('manage', $invitation);

        $token = $this->issueToken->execute();

        $invitation = DB::transaction(function () use ($head, $invitation, $token): FamilyInvitation {
            $lockedInvitation = FamilyInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if (! $lockedInvitation->isPending()) {
                throw ValidationException::withMessages([
                    'invitation' => __('Only pending invitations can be resent.'),
                ]);
            }

            $lockedInvitation->update([
                'invited_by_user_id' => $head->id,
                'token_hash' => FamilyInvitation::hashToken($token),
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
                'declined_at' => null,
                'revoked_at' => null,
            ]);

            return $lockedInvitation;
        }, attempts: 3);

        Notification::route('mail', $invitation->email)
            ->notify(new FamilyInvitationNotification($invitation, $token));

        return $invitation;
    }
}
