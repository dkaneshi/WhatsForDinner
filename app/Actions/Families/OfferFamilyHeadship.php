<?php

namespace App\Actions\Families;

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OfferFamilyHeadship
{
    /**
     * Offer the Head role to an existing family member.
     */
    public function execute(User $head, Family $family, User $member): void
    {
        Gate::forUser($head)->authorize('offerHeadship', [$family, $member]);

        DB::transaction(function () use ($head, $family, $member): void {
            $lockedFamily = Family::query()->lockForUpdate()->findOrFail($family->id);

            if (! $lockedFamily->isHead($head)
                || $lockedFamily->isHead($member)
                || ! $lockedFamily->members()->whereKey($member->id)->exists()) {
                throw ValidationException::withMessages([
                    'member' => __('Leadership can only be offered to a current family member.'),
                ]);
            }

            $lockedFamily->update(['pending_head_user_id' => $member->id]);
        }, attempts: 3);
    }
}
