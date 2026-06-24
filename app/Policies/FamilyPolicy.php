<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\User;

class FamilyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Family $family): bool
    {
        return $family->members()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Family $family): bool
    {
        return $family->isHead($user);
    }

    /**
     * Determine whether the user can manage family invitations.
     */
    public function inviteMembers(User $user, Family $family): bool
    {
        return $family->isHead($user);
    }

    /**
     * Determine whether the user can remove a family member.
     */
    public function removeMember(User $user, Family $family, User $member): bool
    {
        return $family->isHead($user)
            && ! $family->isHead($member)
            && $family->members()->whereKey($member->id)->exists();
    }

    /**
     * Determine whether the user can leave the family.
     */
    public function leave(User $user, Family $family): bool
    {
        return ! $family->isHead($user)
            && $family->members()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can offer the Head role to a member.
     */
    public function offerHeadship(User $user, Family $family, User $member): bool
    {
        return $family->isHead($user)
            && ! $family->isHead($member)
            && $family->members()->whereKey($member->id)->exists();
    }

    /**
     * Determine whether the user can accept the pending Head role.
     */
    public function acceptHeadship(User $user, Family $family): bool
    {
        return $family->pending_head_user_id === $user->id
            && $family->members()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can cancel the pending Head transfer.
     */
    public function cancelHeadship(User $user, Family $family): bool
    {
        return $family->isHead($user) && ! is_null($family->pending_head_user_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Family $family): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Family $family): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Family $family): bool
    {
        return false;
    }
}
