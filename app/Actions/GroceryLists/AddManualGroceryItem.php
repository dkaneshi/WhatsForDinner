<?php

namespace App\Actions\GroceryLists;

use App\Models\GroceryList;
use App\Models\GroceryListItem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AddManualGroceryItem
{
    public function __construct(private NormalizeGroceryItemName $normalizeGroceryItemName) {}

    /**
     * Add a manual item for one family's weekly grocery list.
     */
    public function execute(GroceryList $groceryList, string $name): GroceryListItem
    {
        Gate::authorize('update', $groceryList->weeklyPlan);

        $validated = Validator::make(['name' => $name], [
            'name' => ['required', 'string', 'max:100'],
        ])->validate();

        $normalizedName = $this->normalizeGroceryItemName->execute($validated['name']);

        if ($groceryList->items()->where('normalized_name', $normalizedName)->exists()) {
            throw ValidationException::withMessages([
                'manualItemName' => __('This item is already on the grocery list.'),
            ]);
        }

        return $groceryList->items()->create([
            'name' => str($validated['name'])->squish()->toString(),
            'normalized_name' => $normalizedName,
            'is_checked' => false,
            'is_manual' => true,
            'is_suppressed' => false,
            'source_entry_ids' => null,
            'source_labels' => null,
        ]);
    }
}
