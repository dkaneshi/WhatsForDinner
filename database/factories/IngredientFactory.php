<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\Ingredient;
use App\ProteinCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lexify('Ingredient ?????');

        return [
            'family_id' => Family::factory(),
            'name' => Str::title($name),
            'normalized_name' => Str::lower($name),
            'protein_category' => fake()->optional()->randomElement(ProteinCategory::cases()),
        ];
    }
}
