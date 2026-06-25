<?php

namespace App\Actions\DishImports;

use App\Actions\Dishes\NormalizeDishName;
use App\Actions\Ingredients\NormalizeIngredientName;
use App\Models\Family;
use App\Models\Ingredient;
use App\ProteinCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ValidateDishImportPreview
{
    public function __construct(
        private NormalizeDishName $normalizeDishName,
        private NormalizeIngredientName $normalizeIngredientName,
    ) {}

    /**
     * Validate and annotate an editable import preview.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array{name: string, note: string, ingredients: list<array{name: string, protein_category: string|null, is_main_protein: bool, matched_ingredient_id: int|null, suggestions: list<string>}>, errors: list<string>, warnings: list<string>}>, is_valid: bool}
     */
    public function execute(Family $family, array $rows): array
    {
        if ($rows === []) {
            return [
                'rows' => [],
                'is_valid' => false,
            ];
        }

        $normalizedDishNames = [];
        $normalizedIngredientCategories = [];
        $annotatedRows = [];

        $existingIngredients = $family->ingredients()->orderBy('name')->get();
        $existingIngredientsByNormalizedName = $existingIngredients->keyBy('normalized_name');
        $existingDishNames = $family->dishes()->pluck('name', 'normalized_name');

        foreach ($rows as $row) {
            $annotatedRow = $this->emptyRow($row);
            $normalizedDishName = $this->normalizeDishName->execute($annotatedRow['name'])['normalized_name'];

            if ($annotatedRow['name'] === '') {
                $annotatedRow['errors'][] = $this->message('Dish name is required.');
            } elseif (isset($normalizedDishNames[$normalizedDishName])) {
                $annotatedRow['errors'][] = $this->message('Another imported dish is already named :name.', ['name' => $annotatedRow['name']]);
            } elseif ($existingDishNames->has($normalizedDishName)) {
                $annotatedRow['errors'][] = $this->message('A dish named :name already exists in this family.', ['name' => (string) $existingDishNames[$normalizedDishName]]);
            }

            $normalizedDishNames[$normalizedDishName] = true;

            if ($annotatedRow['ingredients'] === []) {
                $annotatedRow['errors'][] = $this->message('Add at least one ingredient.');
            }

            $mainProteinCount = collect($annotatedRow['ingredients'])
                ->filter(fn (array $ingredient): bool => $ingredient['is_main_protein'])
                ->count();

            if ($mainProteinCount !== 1) {
                $annotatedRow['errors'][] = $this->message('Choose exactly one ingredient as the main protein.');
            }

            $normalizedIngredientNamesInDish = [];
            $annotatedIngredients = [];

            foreach ($annotatedRow['ingredients'] as $ingredient) {
                $annotatedIngredient = $ingredient;
                $normalizedIngredient = $this->normalizeIngredientName->execute($ingredient['name']);
                $normalizedIngredientName = $normalizedIngredient['normalized_name'];
                $existingIngredient = $existingIngredientsByNormalizedName->get($normalizedIngredientName);

                if ($ingredient['name'] === '') {
                    $annotatedRow['errors'][] = $this->message('Every ingredient needs a name.');
                } elseif (isset($normalizedIngredientNamesInDish[$normalizedIngredientName])) {
                    $annotatedRow['errors'][] = $this->message('The ingredient :name appears more than once in :dish.', [
                        'name' => $ingredient['name'],
                        'dish' => $annotatedRow['name'] ?: $this->message('this dish'),
                    ]);
                }

                $normalizedIngredientNamesInDish[$normalizedIngredientName] = true;

                if ($ingredient['is_main_protein'] && is_null($ingredient['protein_category'])) {
                    $annotatedRow['errors'][] = $this->message('The main protein :name needs a protein category.', ['name' => $ingredient['name']]);
                }

                if (! is_null($ingredient['protein_category'])
                    && is_null(ProteinCategory::tryFrom($ingredient['protein_category']))) {
                    $annotatedRow['errors'][] = $this->message('The protein category for :name is not supported.', ['name' => $ingredient['name']]);
                }

                if ($existingIngredient instanceof Ingredient) {
                    $annotatedIngredient['matched_ingredient_id'] = $existingIngredient->id;

                    if (! is_null($ingredient['protein_category'])
                        && ! is_null($existingIngredient->protein_category)
                        && $existingIngredient->protein_category->value !== $ingredient['protein_category']) {
                        $annotatedRow['errors'][] = $this->message('The existing ingredient :name is categorized as :existing, not :incoming.', [
                            'name' => $existingIngredient->name,
                            'existing' => $existingIngredient->protein_category->label(),
                            'incoming' => ProteinCategory::tryFrom($ingredient['protein_category'])?->label() ?? $ingredient['protein_category'],
                        ]);
                    }
                } else {
                    $annotatedIngredient['suggestions'] = $this->suggestLikelyDuplicates($ingredient['name'], $existingIngredients);

                    foreach ($annotatedIngredient['suggestions'] as $suggestion) {
                        $annotatedRow['warnings'][] = $this->message(':name may already exist as :suggestion.', [
                            'name' => $ingredient['name'],
                            'suggestion' => $suggestion,
                        ]);
                    }
                }

                if (! is_null($ingredient['protein_category'])) {
                    if (isset($normalizedIngredientCategories[$normalizedIngredientName])
                        && $normalizedIngredientCategories[$normalizedIngredientName] !== $ingredient['protein_category']) {
                        $annotatedRow['errors'][] = $this->message('The imported ingredient :name uses conflicting protein categories.', ['name' => $ingredient['name']]);
                    }

                    $normalizedIngredientCategories[$normalizedIngredientName] = $ingredient['protein_category'];
                }

                $annotatedIngredients[] = $annotatedIngredient;
            }

            $annotatedRow['ingredients'] = $annotatedIngredients;
            $annotatedRows[] = $annotatedRow;
        }

        return [
            'rows' => $annotatedRows,
            'is_valid' => collect($annotatedRows)->every(fn (array $row): bool => $row['errors'] === []),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{name: string, note: string, ingredients: list<array{name: string, protein_category: string|null, is_main_protein: bool, matched_ingredient_id: int|null, suggestions: list<string>}>, errors: list<string>, warnings: list<string>}
     */
    private function emptyRow(array $row): array
    {
        $ingredients = [];
        $rawIngredients = $row['ingredients'] ?? [];

        if (! is_array($rawIngredients)) {
            $rawIngredients = [];
        }

        foreach ($rawIngredients as $ingredient) {
            $ingredients[] = $this->emptyIngredient(is_array($ingredient) ? $ingredient : []);
        }

        return [
            'name' => Str::squish((string) ($row['name'] ?? '')),
            'note' => Str::of((string) ($row['note'] ?? ''))->trim()->toString(),
            'ingredients' => $ingredients,
            'errors' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $ingredient
     * @return array{name: string, protein_category: string|null, is_main_protein: bool, matched_ingredient_id: int|null, suggestions: list<string>}
     */
    private function emptyIngredient(array $ingredient): array
    {
        $category = $ingredient['protein_category'] ?? null;

        return [
            'name' => Str::squish((string) ($ingredient['name'] ?? '')),
            'protein_category' => is_string($category) && $category !== '' ? Str::lower($category) : null,
            'is_main_protein' => (bool) ($ingredient['is_main_protein'] ?? false),
            'matched_ingredient_id' => null,
            'suggestions' => [],
        ];
    }

    /**
     * @param  Collection<int, Ingredient>  $existingIngredients
     * @return list<string>
     */
    private function suggestLikelyDuplicates(string $name, Collection $existingIngredients): array
    {
        $fingerprint = $this->duplicateFingerprint($name);
        $suggestions = [];

        foreach ($existingIngredients as $ingredient) {
            $existingFingerprint = $this->duplicateFingerprint($ingredient->name);

            if ($existingFingerprint === $fingerprint
                || levenshtein($existingFingerprint, $fingerprint) <= 2
                || levenshtein(Str::lower($ingredient->name), Str::lower($name)) <= 2) {
                $suggestions[] = $ingredient->name;
            }

            if (count($suggestions) === 3) {
                break;
            }
        }

        return $suggestions;
    }

    private function duplicateFingerprint(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/u', '')
            ->replaceMatches('/(oes|ies|es|s)$/u', '')
            ->toString();
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function message(string $key, array $replace = []): string
    {
        return (string) __($key, $replace);
    }
}
