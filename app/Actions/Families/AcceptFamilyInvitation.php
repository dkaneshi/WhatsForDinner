<?php

namespace App\Actions\Families;

use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AcceptFamilyInvitation
{
    /**
     * Accept an invitation and add the user to the family.
     */
    public function execute(User $user, FamilyInvitation $invitation): void
    {
        Gate::forUser($user)->authorize('respond', $invitation);

        DB::transaction(function () use ($user, $invitation): void {
            $lockedInvitation = FamilyInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $lockedInvitation->isPending()) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation is no longer available.'),
                ]);
            }

            $lockedInvitation->family->members()->syncWithoutDetaching([$lockedUser->id]);

            if (is_null($lockedUser->current_family_id)) {
                $lockedUser->forceFill(['current_family_id' => $lockedInvitation->family_id])->save();
            }

            $lockedInvitation->update(['accepted_at' => now()]);
        }, attempts: 3);
    }
}
