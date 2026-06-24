<?php

namespace App\Actions\Ingredients;

use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteIngredient
{
    /**
     * Delete an ingredient that is not referenced by any dish.
     */
    public function execute(User $user, Ingredient $ingredient): void
    {
        Gate::forUser($user)->authorize('delete', $ingredient);

        DB::transaction(function () use ($ingredient): void {
            $lockedIngredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);

            if ($lockedIngredient->dishes()->exists()) {
                throw ValidationException::withMessages([
                    'ingredient' => __('This ingredient is used by one or more dishes and cannot be deleted.'),
                ]);
            }

            $lockedIngredient->delete();
        }, attempts: 3);
    }
}
