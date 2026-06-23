<?php

namespace App\Policies;

use App\Models\FamilyInvitation;
use App\Models\User;

class FamilyInvitationPolicy
{
    /**
     * Determine whether the user can manage the invitation.
     */
    public function manage(User $user, FamilyInvitation $familyInvitation): bool
    {
        return $familyInvitation->family->isHead($user);
    }

    /**
     * Determine whether the user can respond to the invitation.
     */
    public function respond(User $user, FamilyInvitation $familyInvitation): bool
    {
        return $user->hasVerifiedEmail() && $familyInvitation->isFor($user);
    }
}
