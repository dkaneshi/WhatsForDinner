<?php

namespace App\Actions\Ingredients;

use App\Actions\WeeklyPlans\RefreshWeeklyPlanEntrySnapshots;
use App\Models\Ingredient;
use App\Models\User;
use App\ProteinCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateIngredient
{
    public function __construct(
        private NormalizeIngredientName $normalizeIngredientName,
        private RefreshWeeklyPlanEntrySnapshots $refreshWeeklyPlanEntrySnapshots,
    ) {}

    /**
     * Update the shared ingredient used by every connected dish.
     *
     * @param  array{name: string, protein_category?: string|null}  $attributes
     */
    public function execute(User $user, Ingredient $ingredient, array $attributes): void
    {
        Gate::forUser($user)->authorize('update', $ingredient);

        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:100'],
            'protein_category' => ['nullable', Rule::enum(ProteinCategory::class)],
        ])->validate();

        $name = $this->normalizeIngredientName->execute($validated['name']);

        DB::transaction(function () use ($ingredient, $name, $validated): void {
            $lockedIngredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);

            $duplicateExists = Ingredient::query()
                ->where('family_id', $lockedIngredient->family_id)
                ->where('normalized_name', $name['normalized_name'])
                ->whereKeyNot($lockedIngredient->id)
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'name' => __('This ingredient already exists in the family.'),
                ]);
            }

            $lockedIngredient->update([
                ...$name,
                'protein_category' => $validated['protein_category'] ?? null,
            ]);

            $this->refreshWeeklyPlanEntrySnapshots->forIngredient($lockedIngredient);
        }, attempts: 3);
    }
}
