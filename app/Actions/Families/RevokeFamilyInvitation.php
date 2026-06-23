<?php

namespace App\Actions\Families;

use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RevokeFamilyInvitation
{
    /**
     * Revoke a pending invitation.
     */
    public function execute(User $head, FamilyInvitation $invitation): void
    {
        Gate::forUser($head)->authorize('manage', $invitation);

        DB::transaction(function () use ($invitation): void {
            $lockedInvitation = FamilyInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if (! $lockedInvitation->isPending()) {
                throw ValidationException::withMessages([
                    'invitation' => __('Only pending invitations can be revoked.'),
                ]);
            }

            $lockedInvitation->update(['revoked_at' => now()]);
        }, attempts: 3);
    }
}
