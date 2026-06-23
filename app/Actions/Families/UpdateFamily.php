<?php

namespace App\Actions\Families;

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UpdateFamily
{
    /**
     * Update the family settings.
     *
     * @param  array{name: string, timezone: string}  $attributes
     */
    public function execute(User $user, Family $family, array $attributes): void
    {
        Gate::forUser($user)->authorize('update', $family);

        $family->update($attributes);
    }
}
