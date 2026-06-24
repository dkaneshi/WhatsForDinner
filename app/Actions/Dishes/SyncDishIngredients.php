<?php

namespace App\Actions\Dishes;

use App\Models\Dish;

class SyncDishIngredients
{
    /**
     * Sync selected ingredients with exactly one main protein.
     *
     * @param  list<int>  $ingredientIds
     */
    public function execute(Dish $dish, array $ingredientIds, int $mainProteinIngredientId): void
    {
        $ingredients = collect($ingredientIds)->mapWithKeys(
            fn (int $ingredientId): array => [
                $ingredientId => ['is_main_protein' => $ingredientId === $mainProteinIngredientId],
            ],
        );

        $dish->ingredients()->sync($ingredients);
    }
}
