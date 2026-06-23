<?php

namespace App\Actions\Families;

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SwitchActiveFamily
{
    /**
     * Select a family the user belongs to.
     */
    public function execute(User $user, Family $family): void
    {
        Gate::forUser($user)->authorize('view', $family);

        $user->forceFill(['current_family_id' => $family->id])->save();
        $user->setRelation('currentFamily', $family);
    }
}
