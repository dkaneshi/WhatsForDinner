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
        $date = Carbon::parse(fake()->dateTimeBetween('-4 weeks', '+4 weeks'))->startOfDay();

        return [
            'family_id' => Family::factory(),
            'week_start_date' => $date->copy()->subDays($date->dayOfWeek)->toDateString(),
            'includes_weekend' => true,
        ];
    }

    /**
     * A frozen legacy five-day (Monday through Friday) plan.
     */
    public function legacy(): static
    {
        return $this->state(fn (): array => [
            'includes_weekend' => false,
        ]);
    }
}
