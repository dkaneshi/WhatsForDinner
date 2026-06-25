<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\WeeklyPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<WeeklyPlan>
 */
class WeeklyPlanFactory extends Factory
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
            'week_start_date' => Carbon::parse(fake()->dateTimeBetween('-4 weeks', '+4 weeks'))
                ->startOfWeek()
                ->toDateString(),
        ];
    }
}
