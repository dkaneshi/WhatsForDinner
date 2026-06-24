<?php

namespace App\Actions\Dishes;

use App\Models\Family;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class ValidateDishAttributes
{
    public function __construct(private NormalizeDishName $normalizeDishName) {}

    /**
     * Validate and normalize dish attributes within a family.
     *
     * @param  array{name: mixed, note?: mixed, ingredient_ids?: mixed, main_protein_ingredient_id?: mixed}  $attributes
     * @return array{name: string, normalized_name: string, note: string|null, ingredient_ids: list<int>, main_protein_ingredient_id: int}
     */
    public function execute(Family $family, array $attributes): array
    {
        $validator = ValidatorFacade::make($attributes, [
            'name' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
            'ingredient_ids' => ['required', 'array', 'min:1'],
            'ingredient_ids.*' => ['required', 'integer', 'distinct'],
            'main_protein_ingredient_id' => ['required', 'integer'],
        ]);

        $validator->after(function (Validator $validator) use ($family, $attributes): void {
            $ingredientIds = $this->integerIds($attributes['ingredient_ids'] ?? null);

            if ($ingredientIds === []) {
                return;
            }

            $ingredients = $family->ingredients()->whereKey($ingredientIds)->get();

            if ($ingredients->count() !== count($ingredientIds)) {
                $validator->errors()->add('ingredient_ids', __('Every ingredient must belong to the active family.'));

                return;
            }

            $mainProteinId = is_numeric($attributes['main_protein_ingredient_id'] ?? null)
                ? (int) $attributes['main_protein_ingredient_id']
                : null;

            if (is_null($mainProteinId) || ! in_array($mainProteinId, $ingredientIds, true)) {
                $validator->errors()->add('main_protein_ingredient_id', __('Choose exactly one selected ingredient as the main protein.'));

                return;
            }

            if (is_null($ingredients->firstWhere('id', $mainProteinId)?->protein_category)) {
                $validator->errors()->add('main_protein_ingredient_id', __('The main protein must have a protein category.'));
            }
        });

        $validated = $validator->validate();
        $name = $this->normalizeDishName->execute($validated['name']);
        $note = Str::of($validated['note'] ?? '')->trim()->toString();

        return [
            ...$name,
            'note' => $note === '' ? null : $note,
            'ingredient_ids' => $this->integerIds($validated['ingredient_ids']),
            'main_protein_ingredient_id' => (int) $validated['main_protein_ingredient_id'],
        ];
    }

    /**
     * @return list<int>
     */
    private function integerIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
