<?php

namespace App\Actions\WeeklyPlans;

use App\Models\User;
use App\Models\WeeklyPlan;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class EnsureWeeklyPlanIsEditable
{
    public function execute(User $user, WeeklyPlan $weeklyPlan): void
    {
        try {
            Gate::forUser($user)->authorize('update', $weeklyPlan);
        } catch (AuthorizationException) {
            throw new AuthorizationException(__('Past weekly plans are read-only.'));
        }
    }
}
