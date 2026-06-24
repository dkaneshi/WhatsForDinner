<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;

class IngredientPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Ingredient $ingredient): bool
    {
        return $ingredient->family->members()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Family $family): bool
    {
        return $family->members()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Ingredient $ingredient): bool
    {
        return $this->view($user, $ingredient);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Ingredient $ingredient): bool
    {
        return $this->view($user, $ingredient);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Ingredient $ingredient): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Ingredient $ingredient): bool
    {
        return false;
    }
}
