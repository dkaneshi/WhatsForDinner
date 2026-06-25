<?php

namespace App\Policies;

use App\Actions\WeeklyPlans\ResolveWeeklyPlanWeek;
use App\Models\Family;
use App\Models\User;
use App\Models\WeeklyPlan;

class WeeklyPlanPolicy
{
    public function __construct(private ResolveWeeklyPlanWeek $resolveWeeklyPlanWeek) {}

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
    public function view(User $user, WeeklyPlan $weeklyPlan): bool
    {
        return $weeklyPlan->family->members()->whereKey($user->id)->exists();
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
    public function update(User $user, WeeklyPlan $weeklyPlan): bool
    {
        return $this->view($user, $weeklyPlan)
            && ! $this->resolveWeeklyPlanWeek->isPastWeek($weeklyPlan->family, $weeklyPlan->week_start_date);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WeeklyPlan $weeklyPlan): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, WeeklyPlan $weeklyPlan): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, WeeklyPlan $weeklyPlan): bool
    {
        return false;
    }
}
