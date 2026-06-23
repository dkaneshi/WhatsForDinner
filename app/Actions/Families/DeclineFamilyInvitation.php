<?php

namespace App\Actions\Families;

use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeclineFamilyInvitation
{
    /**
     * Decline an invitation without joining the family.
     */
    public function execute(User $user, FamilyInvitation $invitation): void
    {
        Gate::forUser($user)->authorize('respond', $invitation);

        DB::transaction(function () use ($invitation): void {
            $lockedInvitation = FamilyInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if (! $lockedInvitation->isPending()) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation is no longer available.'),
                ]);
            }

            $lockedInvitation->update(['declined_at' => now()]);
        }, attempts: 3);
    }
}
