<?php

namespace App\Actions\WeeklyPlans;

use App\Models\WeeklyPlan;
use Illuminate\Database\Eloquent\Collection;

class FindPriorWeeklyPlans
{
    /**
     * Return existing prior weekly plans, treating missing weeks as empty history.
     *
     * @return Collection<int, WeeklyPlan>
     */
    public function execute(WeeklyPlan $weeklyPlan, int $weeks = 2): Collection
    {
        $weekStarts = [];

        for ($week = 1; $week <= $weeks; $week++) {
            $weekStarts[] = $weeklyPlan->week_start_date
                ->copy()
                ->subWeeks($week)
                ->toDateString();
        }

        return WeeklyPlan::query()
            ->where('family_id', $weeklyPlan->family_id)
            ->where(function ($query) use ($weekStarts): void {
                foreach ($weekStarts as $weekStart) {
                    $query->orWhereDate('week_start_date', $weekStart);
                }
            })
            ->orderByDesc('week_start_date')
            ->get();
    }
}
