<?php

namespace App\Actions\WeeklyPlans;

use App\Actions\GroceryLists\ReconcileGroceryList;
use App\Models\User;
use App\Models\WeeklyPlanEntry;
use Illuminate\Support\Facades\Gate;

class MarkWeeklyPlanEntryFresh
{
    public function __construct(private ReconcileGroceryList $reconcileGroceryList) {}

    /**
     * Remove one entry's leftovers designation.
     */
    public function execute(User $user, WeeklyPlanEntry $entry): void
    {
        Gate::forUser($user)->authorize('update', $entry->weeklyPlan);

        $entry->update(['is_leftovers' => false]);
        $this->reconcileGroceryList->execute($entry->weeklyPlan);
    }
}
