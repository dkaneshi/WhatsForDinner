<?php

namespace App\Actions\WeeklyPlans;

use App\Models\Family;
use App\Models\User;
use App\Models\WeeklyPlan;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

class FindOrCreateWeeklyPlan
{
    /**
     * Find or create a family's Monday-based weekly plan.
     */
    public function execute(User $user, Family $family, CarbonInterface $weekStart): WeeklyPlan
    {
        Gate::forUser($user)->authorize('create', [WeeklyPlan::class, $family]);

        $weekStartDate = $weekStart->toDateString();
        $existingPlan = $family->weeklyPlans()
            ->whereDate('week_start_date', $weekStartDate)
            ->first();

        if ($existingPlan instanceof WeeklyPlan) {
            return $existingPlan;
        }

        try {
            return $family->weeklyPlans()->create([
                'week_start_date' => $weekStartDate,
            ]);
        } catch (QueryException $exception) {
            $existingPlan = $family->weeklyPlans()
                ->whereDate('week_start_date', $weekStartDate)
                ->first();

            if ($existingPlan instanceof WeeklyPlan) {
                return $existingPlan;
            }

            throw $exception;
        }
    }
}
