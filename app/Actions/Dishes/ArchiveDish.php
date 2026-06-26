<?php

namespace App\Actions\Dishes;

use App\Actions\WeeklyPlans\RefreshWeeklyPlanEntrySnapshots;
use App\Models\Dish;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ArchiveDish
{
    public function __construct(private RefreshWeeklyPlanEntrySnapshots $refreshWeeklyPlanEntrySnapshots) {}

    /**
     * Archive a dish instead of deleting it.
     */
    public function execute(User $user, Dish $dish): void
    {
        Gate::forUser($user)->authorize('archive', $dish);

        $dish->update(['archived_at' => now()]);
        $this->refreshWeeklyPlanEntrySnapshots->forDish($dish);
    }
}
