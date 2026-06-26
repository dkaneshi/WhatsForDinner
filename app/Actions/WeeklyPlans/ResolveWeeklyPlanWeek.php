<?php

namespace App\Actions\WeeklyPlans;

use App\Models\Family;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ResolveWeeklyPlanWeek
{
    public function currentWeekStart(Family $family, ?CarbonInterface $now = null): CarbonInterface
    {
        return ($now ?? Carbon::now($family->timezone))
            ->copy()
            ->timezone($family->timezone)
            ->startOfDay()
            ->startOfWeek(CarbonInterface::MONDAY);
    }

    public function fromRouteValue(Family $family, ?string $weekStart = null): CarbonInterface
    {
        if (is_null($weekStart) || $weekStart === '') {
            return $this->currentWeekStart($family);
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $weekStart, $family->timezone)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'week' => __('Choose a valid week.'),
            ]);
        }

        if ($date->copy()->startOfWeek(CarbonInterface::MONDAY)->toDateString() !== $date->toDateString()) {
            throw ValidationException::withMessages([
                'week' => __('Weekly plans must start on Monday.'),
            ]);
        }

        return $date;
    }

    public function previousWeek(CarbonInterface $weekStart): CarbonInterface
    {
        return $weekStart->copy()->subWeek()->startOfWeek(CarbonInterface::MONDAY);
    }

    public function nextWeek(CarbonInterface $weekStart): CarbonInterface
    {
        return $weekStart->copy()->addWeek()->startOfWeek(CarbonInterface::MONDAY);
    }

    public function isPastWeek(Family $family, CarbonInterface $weekStart, ?CarbonInterface $now = null): bool
    {
        $familyWeekStart = Carbon::createFromFormat('Y-m-d', $weekStart->toDateString(), $family->timezone)
            ->startOfDay();

        return $familyWeekStart->lt($this->currentWeekStart($family, $now));
    }
}
