<?php

namespace App\Actions\GroceryLists;

use App\Models\GroceryListItem;
use Illuminate\Support\Facades\Gate;

class UpdateGroceryItemState
{
    public function __construct(private ReconcileGroceryList $reconcileGroceryList) {}

    public function setChecked(GroceryListItem $item, bool $isChecked): void
    {
        Gate::authorize('update', $item->groceryList->weeklyPlan);

        $item->update(['is_checked' => $isChecked]);
    }

    public function remove(GroceryListItem $item): void
    {
        Gate::authorize('update', $item->groceryList->weeklyPlan);

        if ($item->is_manual) {
            $item->delete();

            return;
        }

        $item->update([
            'is_checked' => false,
            'is_suppressed' => true,
        ]);
    }

    public function restore(GroceryListItem $item): void
    {
        Gate::authorize('update', $item->groceryList->weeklyPlan);

        if (! $item->is_suppressed) {
            return;
        }

        $requiredItems = $this->reconcileGroceryList->requiredItems($item->groceryList->weeklyPlan);

        if (! $requiredItems->has($item->normalized_name)) {
            $item->delete();

            return;
        }

        $requiredItem = $requiredItems->get($item->normalized_name);

        $item->update([
            'is_suppressed' => false,
            'is_checked' => false,
            'source_entry_ids' => $requiredItem['source_entry_ids'],
            'source_labels' => $requiredItem['source_labels'],
        ]);
    }
}
