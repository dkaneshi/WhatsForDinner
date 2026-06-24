<?php

namespace App\Actions\Families;

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RemoveFamilyMember
{
    public function __construct(private ResolveActiveFamily $resolveActiveFamily) {}

    /**
     * Remove a non-Head member from the family.
     */
    public function execute(User $head, Family $family, User $member): void
    {
        Gate::forUser($head)->authorize('removeMember', [$family, $member]);

        DB::transaction(function () use ($head, $family, $member): void {
            $lockedFamily = Family::query()->lockForUpdate()->findOrFail($family->id);
            $lockedMember = User::query()->lockForUpdate()->findOrFail($member->id);

            if (! $lockedFamily->isHead($head)
                || $lockedFamily->isHead($lockedMember)
                || ! $lockedFamily->members()->whereKey($lockedMember->id)->exists()) {
                throw ValidationException::withMessages([
                    'member' => __('This member can no longer be removed.'),
                ]);
            }

            $lockedFamily->members()->detach($lockedMember);

            if ($lockedFamily->pending_head_user_id === $lockedMember->id) {
                $lockedFamily->update(['pending_head_user_id' => null]);
            }

            if ($lockedMember->current_family_id === $lockedFamily->id) {
                $lockedMember->forceFill(['current_family_id' => null])->save();
            }
        }, attempts: 3);

        $this->resolveActiveFamily->execute($member->refresh());
    }
}
