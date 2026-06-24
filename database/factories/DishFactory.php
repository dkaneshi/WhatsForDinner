<?php

namespace Database\Factories;

use App\Models\Dish;
use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Dish>
 */
class DishFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lexify('Dish ?????');

        return [
            'family_id' => Family::factory(),
            'name' => Str::title($name),
            'normalized_name' => Str::lower($name),
            'note' => fake()->optional()->sentence(),
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
