<?php

namespace App\Actions\Dishes;

use App\Models\Dish;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RestoreDish
{
    /**
     * Restore an archived dish to active selection.
     */
    public function execute(User $user, Dish $dish): void
    {
        Gate::forUser($user)->authorize('restore', $dish);

        $dish->update(['archived_at' => null]);
    }
}
