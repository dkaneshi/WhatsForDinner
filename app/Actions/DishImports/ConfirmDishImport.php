<?php

namespace App\Actions\DishImports;

use App\Actions\Dishes\CreateDish;
use App\Actions\Ingredients\NormalizeIngredientName;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\ProteinCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConfirmDishImport
{
    public function __construct(
        private ValidateDishImportPreview $validateDishImportPreview,
        private NormalizeIngredientName $normalizeIngredientName,
        private CreateDish $createDish,
    ) {}

    /**
     * Save a validated import atomically.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<Dish>
     */
    public function execute(User $user, Family $family, array $rows): array
    {
        Gate::forUser($user)->authorize('create', [Ingredient::class, $family]);
        Gate::forUser($user)->authorize('create', [Dish::class, $family]);

        $validatedPreview = $this->validateDishImportPreview->execute($family, $rows);

        if (! $validatedPreview['is_valid']) {
            throw ValidationException::withMessages([
                'preview' => __('Resolve the flagged import issues before saving.'),
            ]);
        }

        return DB::transaction(function () use ($user, $family, $validatedPreview): array {
            $createdDishes = [];

            foreach ($validatedPreview['rows'] as $row) {
                $ingredientIds = [];
                $mainProteinIngredientId = null;

                foreach ($row['ingredients'] as $ingredient) {
                    $importedIngredient = $this->findOrCreateIngredient($user, $family, $ingredient);
                    $ingredientIds[] = $importedIngredient->id;

                    if ($ingredient['is_main_protein']) {
                        $mainProteinIngredientId = $importedIngredient->id;
                    }
                }

                $createdDishes[] = $this->createDish->execute($user, $family, [
                    'name' => $row['name'],
                    'note' => $row['note'],
                    'ingredient_ids' => $ingredientIds,
                    'main_protein_ingredient_id' => $mainProteinIngredientId,
                ]);
            }

            return $createdDishes;
        }, attempts: 3);
    }

    /**
     * @param  array{name: string, protein_category: string|null, is_main_protein: bool, matched_ingredient_id: int|null, suggestions: list<string>}  $ingredient
     */
    private function findOrCreateIngredient(User $user, Family $family, array $ingredient): Ingredient
    {
        $normalizedName = $this->normalizeIngredientName->execute($ingredient['name']);
        $existingIngredient = $family->ingredients()
            ->where('normalized_name', $normalizedName['normalized_name'])
            ->first();
        $category = is_null($ingredient['protein_category'])
            ? null
            : ProteinCategory::from($ingredient['protein_category']);

        if ($existingIngredient instanceof Ingredient) {
            if (is_null($existingIngredient->protein_category) && ! is_null($category)) {
                Gate::forUser($user)->authorize('update', $existingIngredient);

                $existingIngredient->update([
                    'protein_category' => $category,
                ]);
            }

            return $existingIngredient;
        }

        Gate::forUser($user)->authorize('create', [Ingredient::class, $family]);

        return $family->ingredients()->create([
            ...$normalizedName,
            'protein_category' => $category,
        ]);
    }
}
