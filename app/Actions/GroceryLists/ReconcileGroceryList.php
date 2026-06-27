<?php

namespace App\Actions\GroceryLists;

use App\Models\GroceryList;
use App\Models\GroceryListItem;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconcileGroceryList
{
    public function __construct(private NormalizeGroceryItemName $normalizeGroceryItemName) {}

    /**
     * Recalculate generated grocery items while preserving intentional user state.
     */
    public function execute(WeeklyPlan $weeklyPlan): GroceryList
    {
        return DB::transaction(function () use ($weeklyPlan): GroceryList {
            $groceryList = GroceryList::query()->firstOrCreate([
                'weekly_plan_id' => $weeklyPlan->id,
            ]);

            $requiredItems = $this->requiredItems($weeklyPlan);
            $requiredNormalizedNames = $requiredItems->keys();

            /** @var Collection<string, GroceryListItem> $existingItems */
            $existingItems = $groceryList->items()->get()->keyBy('normalized_name');

            foreach ($requiredItems as $normalizedName => $requiredItem) {
                $existingItem = $existingItems->get($normalizedName);

                if ($existingItem instanceof GroceryListItem && $existingItem->is_manual) {
                    continue;
                }

                if ($existingItem instanceof GroceryListItem) {
                    $existingItem->update([
                        'name' => $existingItem->name,
                        'source_entry_ids' => $requiredItem['source_entry_ids'],
                        'source_labels' => $requiredItem['source_labels'],
                    ]);

                    continue;
                }

                $groceryList->items()->create([
                    'name' => $requiredItem['name'],
                    'normalized_name' => $normalizedName,
                    'is_checked' => false,
                    'is_manual' => false,
                    'is_suppressed' => false,
                    'source_entry_ids' => $requiredItem['source_entry_ids'],
                    'source_labels' => $requiredItem['source_labels'],
                ]);
            }

            $groceryList->items()
                ->where('is_manual', false)
                ->where('is_suppressed', false)
                ->whereNotIn('normalized_name', $requiredNormalizedNames->all())
                ->delete();

            return $groceryList->fresh(['items']);
        }, attempts: 3);
    }

    /**
     * @return Collection<string, array{name: string, source_entry_ids: list<int>, source_labels: list<string>}>
     */
    public function requiredItems(WeeklyPlan $weeklyPlan): Collection
    {
        $items = $weeklyPlan->entries()
            ->with('dish')
            ->whereNotNull('dish_id')
            ->get()
            ->flatMap(function (WeeklyPlanEntry $entry): array {
                return collect($entry->ingredientNames())
                    ->map(function (string $ingredientName) use ($entry): array {
                        return [
                            'name' => $ingredientName,
                            'normalized_name' => $this->normalizeGroceryItemName->execute($ingredientName),
                            'source_entry_id' => $entry->id,
                            'source_label' => $entry->label(),
                        ];
                    })
                    ->all();
            })
            ->groupBy('normalized_name')
            ->map(function (Collection $items): array {
                $firstItem = $items->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->first();

                return [
                    'name' => $firstItem['name'],
                    'source_entry_ids' => $items->pluck('source_entry_id')->unique()->values()->all(),
                    'source_labels' => $items->pluck('source_label')->unique()->sort()->values()->all(),
                ];
            })
            ->sortKeys();

        return $items->mapWithKeys(fn (array $item, string $normalizedName): array => [
            $normalizedName => [
                'name' => (string) $item['name'],
                'source_entry_ids' => array_values(array_map(
                    fn (mixed $entryId): int => (int) $entryId,
                    $item['source_entry_ids'],
                )),
                'source_labels' => array_values(array_map(
                    fn (mixed $sourceLabel): string => (string) $sourceLabel,
                    $item['source_labels'],
                )),
            ],
        ]);
    }
}
