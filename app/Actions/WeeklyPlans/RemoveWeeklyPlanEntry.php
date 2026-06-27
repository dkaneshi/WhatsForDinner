<?php

namespace App\Actions\WeeklyPlans;

use App\Actions\GroceryLists\ReconcileGroceryList;
use App\Models\User;
use App\Models\WeeklyPlanEntry;
use Illuminate\Support\Facades\Gate;

class RemoveWeeklyPlanEntry
{
    public function __construct(private ReconcileGroceryList $reconcileGroceryList) {}

    /**
     * Remove one scheduled dinner entry.
     */
    public function execute(User $user, WeeklyPlanEntry $entry): void
    {
        Gate::forUser($user)->authorize('update', $entry->weeklyPlan);

        $weeklyPlan = $entry->weeklyPlan;
        $entry->delete();
        $this->reconcileGroceryList->execute($weeklyPlan);
    }
}
