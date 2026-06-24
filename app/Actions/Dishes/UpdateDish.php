<?php

namespace App\Actions\Dishes;

use App\Models\Dish;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateDish
{
    public function __construct(
        private ValidateDishAttributes $validateDishAttributes,
        private SyncDishIngredients $syncDishIngredients,
    ) {}

    /**
     * Update a dish and its ingredient selections atomically.
     *
     * @param  array{name: mixed, note?: mixed, ingredient_ids?: mixed, main_protein_ingredient_id?: mixed}  $attributes
     */
    public function execute(User $user, Dish $dish, array $attributes): void
    {
        Gate::forUser($user)->authorize('update', $dish);

        $validated = $this->validateDishAttributes->execute($dish->family, $attributes);

        DB::transaction(function () use ($dish, $validated): void {
            $lockedDish = Dish::query()->lockForUpdate()->findOrFail($dish->id);

            $duplicateExists = Dish::query()
                ->where('family_id', $lockedDish->family_id)
                ->where('normalized_name', $validated['normalized_name'])
                ->whereKeyNot($lockedDish->id)
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'name' => __('A dish with this name already exists in the family.'),
                ]);
            }

            $lockedDish->update([
                'name' => $validated['name'],
                'normalized_name' => $validated['normalized_name'],
                'note' => $validated['note'],
            ]);

            $this->syncDishIngredients->execute(
                $lockedDish,
                $validated['ingredient_ids'],
                $validated['main_protein_ingredient_id'],
            );
        }, attempts: 3);
    }
}
