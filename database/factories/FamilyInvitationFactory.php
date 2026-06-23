<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\FamilyInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FamilyInvitation>
 */
class FamilyInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'invited_by_user_id' => function (array $attributes): int {
                $family = Family::query()->find($attributes['family_id']);

                if (! $family instanceof Family) {
                    throw new \RuntimeException('A family is required to create an invitation.');
                }

                return $family->head_user_id;
            },
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => FamilyInvitation::hashToken(Str::random(64)),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'declined_at' => null,
            'revoked_at' => null,
        ];
    }
}
