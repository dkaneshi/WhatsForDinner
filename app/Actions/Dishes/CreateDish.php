<?php

namespace App\Actions\Dishes;

use App\Models\Dish;
use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateDish
{
    public function __construct(
        private ValidateDishAttributes $validateDishAttributes,
        private SyncDishIngredients $syncDishIngredients,
    ) {}

    /**
     * Create a valid family dish.
     *
     * @param  array{name: mixed, note?: mixed, ingredient_ids?: mixed, main_protein_ingredient_id?: mixed}  $attributes
     */
    public function execute(User $user, Family $family, array $attributes): Dish
    {
        Gate::forUser($user)->authorize('create', [Dish::class, $family]);

        $validated = $this->validateDishAttributes->execute($family, $attributes);
        $duplicate = $family->dishes()->where('normalized_name', $validated['normalized_name'])->first();

        if ($duplicate?->archived_at) {
            throw ValidationException::withMessages([
                'archived_dish' => __('An archived dish with this name already exists. Restore it or replace it with this version.'),
            ]);
        }

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => __('A dish with this name already exists in the family.'),
            ]);
        }

        return DB::transaction(function () use ($family, $validated): Dish {
            $dish = $family->dishes()->create([
                'name' => $validated['name'],
                'normalized_name' => $validated['normalized_name'],
                'note' => $validated['note'],
            ]);

            $this->syncDishIngredients->execute(
                $dish,
                $validated['ingredient_ids'],
                $validated['main_protein_ingredient_id'],
            );

            return $dish;
        }, attempts: 3);
    }
}
