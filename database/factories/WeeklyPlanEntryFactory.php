<?php

namespace Database\Factories;

use App\Models\Dish;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use App\WeeklyPlanEntrySlot;
use App\WeeklyPlanSpecialEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyPlanEntry>
 */
class WeeklyPlanEntryFactory extends Factory
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
            'weekday' => fake()->numberBetween(1, 5),
            'slot' => WeeklyPlanEntrySlot::Main,
            'is_leftovers' => false,
        ];
    }

    public function alternative(): static
    {
        return $this->state(fn (): array => [
            'slot' => WeeklyPlanEntrySlot::Alternative,
        ]);
    }

    public function eatOut(): static
    {
        return $this->state(fn (): array => [
            'dish_id' => null,
            'special_entry' => WeeklyPlanSpecialEntry::EatOut,
            'slot' => WeeklyPlanEntrySlot::Main,
            'is_leftovers' => false,
        ]);
    }

    public function tvDinnerNight(): static
    {
        return $this->state(fn (): array => [
            'dish_id' => null,
            'special_entry' => WeeklyPlanSpecialEntry::TvDinnerNight,
            'slot' => WeeklyPlanEntrySlot::Main,
            'is_leftovers' => false,
        ]);
    }
}
