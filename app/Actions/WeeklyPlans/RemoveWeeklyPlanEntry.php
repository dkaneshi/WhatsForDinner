<?php

namespace App\Actions\WeeklyPlans;

use App\Models\User;
use App\Models\WeeklyPlanEntry;
use Illuminate\Support\Facades\Gate;

class RemoveWeeklyPlanEntry
{
    /**
     * Remove one scheduled dinner entry.
     */
    public function execute(User $user, WeeklyPlanEntry $entry): void
    {
        Gate::forUser($user)->authorize('update', $entry->weeklyPlan);

        $entry->delete();
    }
}
