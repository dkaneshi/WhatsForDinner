<?php

namespace App\Actions\Families;

use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use App\Notifications\FamilyInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendFamilyInvitation
{
    public function __construct(private IssueFamilyInvitationToken $issueToken) {}

    /**
     * Send or replace an invitation for an email address.
     */
    public function execute(User $head, Family $family, string $email): FamilyInvitation
    {
        Gate::forUser($head)->authorize('inviteMembers', $family);

        $email = Str::lower(trim($email));

        $isAlreadyMember = $family->members()
            ->get(['users.email'])
            ->contains(fn (User $member): bool => Str::lower($member->email) === $email);

        if ($isAlreadyMember) {
            throw ValidationException::withMessages([
                'email' => __('This person is already a member of the family.'),
            ]);
        }

        $token = $this->issueToken->execute();

        $invitation = DB::transaction(function () use ($head, $family, $email, $token): FamilyInvitation {
            return FamilyInvitation::query()->updateOrCreate(
                ['family_id' => $family->id, 'email' => $email],
                [
                    'invited_by_user_id' => $head->id,
                    'token_hash' => FamilyInvitation::hashToken($token),
                    'expires_at' => now()->addDays(7),
                    'accepted_at' => null,
                    'declined_at' => null,
                    'revoked_at' => null,
                ],
            );
        }, attempts: 3);

        Notification::route('mail', $email)
            ->notify(new FamilyInvitationNotification($invitation, $token));

        return $invitation;
    }
}
