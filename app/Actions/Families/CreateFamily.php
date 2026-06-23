<?php

namespace App\Actions\Families;

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateFamily
{
    /**
     * Create a family and make its creator the Head and first member.
     *
     * @param  array{name: string, timezone: string}  $attributes
     */
    public function execute(User $user, array $attributes): Family
    {
        Gate::forUser($user)->authorize('create', Family::class);

        return DB::transaction(function () use ($user, $attributes): Family {
            $family = Family::query()->create([
                'name' => $attributes['name'],
                'timezone' => $attributes['timezone'],
                'head_user_id' => $user->id,
            ]);

            $family->members()->attach($user);
            $user->forceFill(['current_family_id' => $family->id])->save();

            return $family;
        }, attempts: 3);
    }
}
