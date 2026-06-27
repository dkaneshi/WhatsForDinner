<?php

namespace App\Actions\WeeklyPlans;

use App\Actions\GroceryLists\ReconcileGroceryList;
use App\Models\Dish;
use App\Models\Ingredient;
use App\Models\WeeklyPlanEntry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class RefreshWeeklyPlanEntrySnapshots
{
    public function __construct(
        private ResolveWeeklyPlanWeek $resolveWeeklyPlanWeek,
        private ReconcileGroceryList $reconcileGroceryList,
    ) {}

    /**
     * Refresh snapshots for current and future entries using this dish.
     */
    public function forDish(Dish $dish): void
    {
        /** @var EloquentCollection<int, WeeklyPlanEntry> $entries */
        $entries = WeeklyPlanEntry::query()
            ->where('dish_id', $dish->id)
            ->with(['weeklyPlan.family', 'dish.ingredients'])
            ->get();

        $activeEntries = $entries
            ->reject(fn (WeeklyPlanEntry $entry): bool => $this->resolveWeeklyPlanWeek->isPastWeek(
                $entry->weeklyPlan->family,
                $entry->weeklyPlan->week_start_date,
            ));

        $activeEntries->each(fn (WeeklyPlanEntry $entry): bool => $this->forEntry($entry));

        $activeEntries
            ->pluck('weeklyPlan')
            ->unique('id')
            ->each(fn ($weeklyPlan) => $this->reconcileGroceryList->execute($weeklyPlan));
    }

    /**
     * Refresh snapshots for current and future entries using dishes that contain this ingredient.
     */
    public function forIngredient(Ingredient $ingredient): void
    {
        $ingredient->dishes()
            ->with('ingredients')
            ->get()
            ->each(fn (Dish $dish): null => $this->forDish($dish));
    }

    /**
     * Refresh one scheduled entry's stored dish snapshot.
     */
    public function forEntry(WeeklyPlanEntry $entry): bool
    {
        if (! $entry->dish instanceof Dish) {
            return false;
        }

        return $entry->update($this->snapshotForDish($entry->dish));
    }

    /**
     * @return array{dish_snapshot_name: string, dish_snapshot_note: string|null, dish_snapshot_ingredients: list<array{name: string, is_main_protein: bool}>}
     */
    private function snapshotForDish(Dish $dish): array
    {
        $dish->loadMissing('ingredients');

        return [
            'dish_snapshot_name' => $dish->name,
            'dish_snapshot_note' => $dish->note,
            'dish_snapshot_ingredients' => $dish->ingredients
                ->sortBy('name')
                ->values()
                ->map(fn ($ingredient): array => [
                    'name' => $ingredient->name,
                    'is_main_protein' => (bool) $ingredient->pivot->is_main_protein,
                ])
                ->all(),
        ];
    }
}
