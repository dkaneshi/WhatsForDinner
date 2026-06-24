<?php

namespace App\Actions\Families;

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AcceptFamilyHeadship
{
    /**
     * Accept a pending Head transfer atomically.
     */
    public function execute(User $member, Family $family): void
    {
        Gate::forUser($member)->authorize('acceptHeadship', $family);

        DB::transaction(function () use ($member, $family): void {
            $lockedFamily = Family::query()->lockForUpdate()->findOrFail($family->id);

            if ($lockedFamily->pending_head_user_id !== $member->id
                || ! $lockedFamily->members()->whereKey($member->id)->exists()) {
                throw ValidationException::withMessages([
                    'family' => __('This leadership offer is no longer available.'),
                ]);
            }

            $lockedFamily->update([
                'head_user_id' => $member->id,
                'pending_head_user_id' => null,
            ]);
        }, attempts: 3);
    }
}
