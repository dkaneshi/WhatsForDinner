<?php

namespace Database\Factories;

use App\Models\GroceryList;
use App\Models\WeeklyPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroceryList>
 */
class GroceryListFactory extends Factory
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
        ];
    }
}
