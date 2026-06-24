<?php

namespace App\Actions\Dishes;

use App\Models\Dish;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReplaceArchivedDish
{
    public function __construct(
        private ValidateDishAttributes $validateDishAttributes,
        private SyncDishIngredients $syncDishIngredients,
    ) {}

    /**
     * Replace an archived dish's details and restore it.
     *
     * @param  array{name: mixed, note?: mixed, ingredient_ids?: mixed, main_protein_ingredient_id?: mixed}  $attributes
     */
    public function execute(User $user, Dish $dish, array $attributes): void
    {
        Gate::forUser($user)->authorize('update', $dish);

        $validated = $this->validateDishAttributes->execute($dish->family, $attributes);

        DB::transaction(function () use ($dish, $validated): void {
            $lockedDish = Dish::query()->lockForUpdate()->findOrFail($dish->id);

            if (is_null($lockedDish->archived_at) || $lockedDish->normalized_name !== $validated['normalized_name']) {
                throw ValidationException::withMessages([
                    'archived_dish' => __('This archived dish conflict is no longer available.'),
                ]);
            }

            $lockedDish->update([
                'name' => $validated['name'],
                'normalized_name' => $validated['normalized_name'],
                'note' => $validated['note'],
                'archived_at' => null,
            ]);

            $this->syncDishIngredients->execute(
                $lockedDish,
                $validated['ingredient_ids'],
                $validated['main_protein_ingredient_id'],
            );
        }, attempts: 3);
    }
}
