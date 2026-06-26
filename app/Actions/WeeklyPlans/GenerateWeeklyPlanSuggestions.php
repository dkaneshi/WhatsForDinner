<?php

namespace App\Actions\WeeklyPlans;

use App\Models\Dish;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\ProteinCategory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class GenerateWeeklyPlanSuggestions
{
    /**
     * Generate an explainable set of up to ten unique dish suggestions.
     *
     * @return Collection<int, Dish>
     */
    public function execute(User $user, WeeklyPlan $weeklyPlan): Collection
    {
        Gate::forUser($user)->authorize('view', $weeklyPlan);

        $scheduledDishIds = $this->scheduledDishIds($weeklyPlan);
        $recentDishIds = $this->recentDishIds($weeklyPlan);
        $eligibleDishes = $weeklyPlan->family->dishes()
            ->active()
            ->with('mainProtein')
            ->whereKeyNot($scheduledDishIds)
            ->get()
            ->filter(fn (Dish $dish): bool => ! is_null($dish->proteinCategory()))
            ->values();

        $selected = collect();

        foreach (ProteinCategory::cases() as $category) {
            $categoryCandidates = $eligibleDishes
                ->reject(fn (Dish $dish): bool => in_array($dish->id, $recentDishIds, true))
                ->filter(fn (Dish $dish): bool => $dish->proteinCategory() === $category)
                ->shuffle()
                ->take(2);

            $selected = $selected->merge($categoryCandidates);
        }

        $selected = $this->fillFromCandidates(
            selected: $selected,
            candidates: $eligibleDishes->reject(fn (Dish $dish): bool => in_array($dish->id, $recentDishIds, true)),
        );

        $selected = $this->fillFromCandidates(
            selected: $selected,
            candidates: $eligibleDishes->filter(fn (Dish $dish): bool => in_array($dish->id, $recentDishIds, true)),
        );

        return $selected->unique('id')->values();
    }

    /**
     * @param  Collection<int, Dish>  $selected
     * @param  Collection<int, Dish>  $candidates
     * @return Collection<int, Dish>
     */
    private function fillFromCandidates(Collection $selected, Collection $candidates): Collection
    {
        if ($selected->count() >= 10) {
            return $selected->take(10)->values();
        }

        $selectedIds = $selected
            ->map(fn (Dish $dish): int => $dish->id)
            ->all();
        $fillers = $candidates
            ->reject(fn (Dish $dish): bool => in_array($dish->id, $selectedIds, true))
            ->shuffle()
            ->take(10 - $selected->count());

        return $selected
            ->merge($fillers)
            ->unique('id')
            ->take(10)
            ->values();
    }

    /**
     * @return list<int>
     */
    private function scheduledDishIds(WeeklyPlan $weeklyPlan): array
    {
        $dishIds = [];

        foreach ($weeklyPlan->entries()->pluck('dish_id') as $dishId) {
            $dishIds[] = (int) $dishId;
        }

        return array_values(array_unique($dishIds));
    }

    /**
     * @return list<int>
     */
    private function recentDishIds(WeeklyPlan $weeklyPlan): array
    {
        $priorWeekStarts = [
            $weeklyPlan->week_start_date->copy()->subWeek()->toDateString(),
            $weeklyPlan->week_start_date->copy()->subWeeks(2)->toDateString(),
        ];

        /** @var EloquentCollection<int, WeeklyPlan> $priorPlans */
        $priorPlans = WeeklyPlan::query()
            ->where('family_id', $weeklyPlan->family_id)
            ->where(function ($query) use ($priorWeekStarts): void {
                foreach ($priorWeekStarts as $weekStart) {
                    $query->orWhereDate('week_start_date', $weekStart);
                }
            })
            ->with('entries')
            ->get();

        $dishIds = [];

        foreach ($priorPlans as $priorPlan) {
            foreach ($priorPlan->entries as $entry) {
                $dishIds[] = $entry->dish_id;
            }
        }

        return array_values(array_unique($dishIds));
    }
}
