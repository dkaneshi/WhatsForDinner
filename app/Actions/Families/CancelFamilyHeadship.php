<?php

namespace App\Actions\Families;

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelFamilyHeadship
{
    /**
     * Cancel the pending Head transfer.
     */
    public function execute(User $head, Family $family): void
    {
        Gate::forUser($head)->authorize('cancelHeadship', $family);

        DB::transaction(function () use ($head, $family): void {
            $lockedFamily = Family::query()->lockForUpdate()->findOrFail($family->id);

            if (! $lockedFamily->isHead($head) || is_null($lockedFamily->pending_head_user_id)) {
                throw ValidationException::withMessages([
                    'family' => __('There is no leadership offer to cancel.'),
                ]);
            }

            $lockedFamily->update(['pending_head_user_id' => null]);
        }, attempts: 3);
    }
}
