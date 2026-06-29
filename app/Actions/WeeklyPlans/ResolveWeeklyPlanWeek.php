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
        $today = ($now ?? Carbon::now($family->timezone))
            ->copy()
            ->timezone($family->timezone)
            ->startOfDay();

        return $this->anchorToSunday($today);
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

        // New weeks anchor to Sunday. Legacy plans stored on their original
        // (Monday) start date remain directly reachable so past history renders.
        $isSundayAnchored = $this->anchorToSunday($date)->toDateString() === $date->toDateString();

        if (! $isSundayAnchored && ! $this->matchesExistingPlan($family, $date)) {
            throw ValidationException::withMessages([
                'week' => __('Weekly plans must start on Sunday.'),
            ]);
        }

        return $date;
    }

    public function previousWeek(CarbonInterface $weekStart): CarbonInterface
    {
        return $this->anchorToSunday($weekStart->copy()->subWeek());
    }

    public function nextWeek(CarbonInterface $weekStart): CarbonInterface
    {
        return $this->anchorToSunday($weekStart->copy()->addWeek());
    }

    public function isPastWeek(Family $family, CarbonInterface $weekStart, ?CarbonInterface $now = null): bool
    {
        $familyWeekStart = Carbon::createFromFormat('Y-m-d', $weekStart->toDateString(), $family->timezone)
            ->startOfDay();

        return $familyWeekStart->lt($this->currentWeekStart($family, $now));
    }

    /**
     * Anchor a date to the Sunday that begins its week.
     *
     * Sunday is treated as the first day of the week (Sunday through Saturday),
     * independent of Carbon's locale-driven default of Monday.
     */
    private function anchorToSunday(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->startOfDay()->subDays($date->dayOfWeek);
    }

    private function matchesExistingPlan(Family $family, CarbonInterface $date): bool
    {
        return $family->weeklyPlans()
            ->whereDate('week_start_date', $date->toDateString())
            ->exists();
    }
}
