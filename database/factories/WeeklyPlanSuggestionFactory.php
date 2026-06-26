<?php

namespace Database\Factories;

use App\Models\Dish;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyPlanSuggestion>
 */
class WeeklyPlanSuggestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weekly_plan_id' => WeeklyPlan::factory(),
            'dish_id' => Dish::factory(),
            'position' => fake()->numberBetween(1, 10),
        ];
    }
}
