<?php

namespace App\Actions\Families;

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LeaveFamily
{
    public function __construct(private ResolveActiveFamily $resolveActiveFamily) {}

    /**
     * Remove the member's own family membership.
     */
    public function execute(User $member, Family $family): void
    {
        Gate::forUser($member)->authorize('leave', $family);

        DB::transaction(function () use ($member, $family): void {
            $lockedFamily = Family::query()->lockForUpdate()->findOrFail($family->id);
            $lockedMember = User::query()->lockForUpdate()->findOrFail($member->id);

            if ($lockedFamily->isHead($lockedMember)
                || ! $lockedFamily->members()->whereKey($lockedMember->id)->exists()) {
                throw ValidationException::withMessages([
                    'family' => __('You can no longer leave this family.'),
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
