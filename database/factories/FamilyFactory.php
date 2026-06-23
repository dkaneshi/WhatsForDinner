<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Family>
 */
class FamilyFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Family $family): void {
            $family->members()->syncWithoutDetaching([$family->head_user_id]);

            $head = $family->head;

            if (is_null($head->current_family_id)) {
                $head->forceFill(['current_family_id' => $family->id])->saveQuietly();
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->lastName().' Family',
            'timezone' => fake()->timezone(),
            'head_user_id' => User::factory(),
        ];
    }
}
