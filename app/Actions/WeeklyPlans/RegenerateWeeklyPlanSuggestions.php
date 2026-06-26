<?php

namespace App\Actions\WeeklyPlans;

use App\Models\Dish;
use App\Models\User;
use App\Models\WeeklyPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RegenerateWeeklyPlanSuggestions
{
    public function __construct(private GenerateWeeklyPlanSuggestions $generateWeeklyPlanSuggestions) {}

    /**
     * Replace suggestion rows without touching scheduled entries.
     *
     * @return Collection<int, Dish>
     */
    public function execute(User $user, WeeklyPlan $weeklyPlan): Collection
    {
        Gate::forUser($user)->authorize('update', $weeklyPlan);

        $suggestions = $this->generateWeeklyPlanSuggestions->execute($user, $weeklyPlan);

        DB::transaction(function () use ($weeklyPlan, $suggestions): void {
            $weeklyPlan->suggestions()->delete();

            foreach ($suggestions->values() as $index => $dish) {
                $weeklyPlan->suggestions()->create([
                    'dish_id' => $dish->id,
                    'position' => $index + 1,
                ]);
            }
        }, attempts: 3);

        return $suggestions;
    }
}
