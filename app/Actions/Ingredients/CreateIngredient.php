<?php

namespace App\Actions\Ingredients;

use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\ProteinCategory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateIngredient
{
    public function __construct(private NormalizeIngredientName $normalizeIngredientName) {}

    /**
     * Create a normalized ingredient for a family.
     *
     * @param  array{name: string, protein_category?: string|null}  $attributes
     */
    public function execute(User $user, Family $family, array $attributes): Ingredient
    {
        Gate::forUser($user)->authorize('create', [Ingredient::class, $family]);

        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:100'],
            'protein_category' => ['nullable', Rule::enum(ProteinCategory::class)],
        ])->validate();

        $name = $this->normalizeIngredientName->execute($validated['name']);

        if ($family->ingredients()->where('normalized_name', $name['normalized_name'])->exists()) {
            throw ValidationException::withMessages([
                'name' => __('This ingredient already exists in the family.'),
            ]);
        }

        return $family->ingredients()->create([
            ...$name,
            'protein_category' => $validated['protein_category'] ?? null,
        ]);
    }
}
