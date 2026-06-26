<?php

namespace App\Actions\WeeklyPlans;

use App\Models\Dish;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use App\WeeklyPlanEntrySlot;
use App\WeeklyPlanSpecialEntry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ScheduleWeeklyPlanEntry
{
    public function __construct(private RefreshWeeklyPlanEntrySnapshots $refreshWeeklyPlanEntrySnapshots) {}

    /**
     * Schedule one weekly dinner entry.
     */
    public function execute(
        User $user,
        WeeklyPlan $weeklyPlan,
        int $weekday,
        WeeklyPlanEntrySlot $slot,
        ?Dish $dish = null,
        ?WeeklyPlanSpecialEntry $specialEntry = null,
        ?bool $isLeftovers = null,
    ): WeeklyPlanEntry {
        Gate::forUser($user)->authorize('update', $weeklyPlan);

        if ($weekday < 1 || $weekday > 5) {
            throw ValidationException::withMessages([
                'weekday' => __('Choose a weekday from Monday through Friday.'),
            ]);
        }

        if ($weeklyPlan->entries()->where('weekday', $weekday)->where('slot', $slot)->exists()) {
            throw ValidationException::withMessages([
                'slot' => __('That dinner slot is already filled.'),
            ]);
        }

        if ($dish instanceof Dish && $specialEntry instanceof WeeklyPlanSpecialEntry) {
            throw ValidationException::withMessages([
                'entry' => __('Choose either a dish or a special entry.'),
            ]);
        }

        if (! $dish instanceof Dish && ! $specialEntry instanceof WeeklyPlanSpecialEntry) {
            throw ValidationException::withMessages([
                'entry' => __('Choose a dish or a special entry.'),
            ]);
        }

        if ($specialEntry instanceof WeeklyPlanSpecialEntry) {
            return $this->scheduleSpecialEntry($weeklyPlan, $weekday, $slot, $specialEntry);
        }

        return $this->scheduleDish($weeklyPlan, $weekday, $slot, $dish, $isLeftovers);
    }

    private function scheduleSpecialEntry(
        WeeklyPlan $weeklyPlan,
        int $weekday,
        WeeklyPlanEntrySlot $slot,
        WeeklyPlanSpecialEntry $specialEntry,
    ): WeeklyPlanEntry {
        if ($slot !== WeeklyPlanEntrySlot::Main) {
            throw ValidationException::withMessages([
                'slot' => __('Special entries can only be scheduled as the main dinner.'),
            ]);
        }

        if ($weeklyPlan->entries()->where('weekday', $weekday)->where('slot', WeeklyPlanEntrySlot::Alternative)->exists()) {
            throw ValidationException::withMessages([
                'slot' => __('Special entries cannot have an alternative dish.'),
            ]);
        }

        return $weeklyPlan->entries()->create([
            'dish_id' => null,
            'special_entry' => $specialEntry,
            'weekday' => $weekday,
            'slot' => $slot,
            'is_leftovers' => false,
        ]);
    }

    private function scheduleDish(
        WeeklyPlan $weeklyPlan,
        int $weekday,
        WeeklyPlanEntrySlot $slot,
        Dish $dish,
        ?bool $isLeftovers,
    ): WeeklyPlanEntry {
        if ($dish->family_id !== $weeklyPlan->family_id || $dish->isArchived()) {
            throw ValidationException::withMessages([
                'dish' => __('Choose an active dish from this family.'),
            ]);
        }

        if ($slot === WeeklyPlanEntrySlot::Alternative && $weeklyPlan->entries()->where('weekday', $weekday)->whereNotNull('special_entry')->exists()) {
            throw ValidationException::withMessages([
                'slot' => __('Special entries cannot have an alternative dish.'),
            ]);
        }

        $entry = $weeklyPlan->entries()->create([
            'dish_id' => $dish->id,
            'special_entry' => null,
            'weekday' => $weekday,
            'slot' => $slot,
            'is_leftovers' => $isLeftovers ?? $this->defaultsToLeftovers($weeklyPlan, $dish, $weekday),
        ]);

        $entry->setRelation('dish', $dish);
        $this->refreshWeeklyPlanEntrySnapshots->forEntry($entry);

        return $entry->refresh();
    }

    private function defaultsToLeftovers(WeeklyPlan $weeklyPlan, Dish $dish, int $weekday): bool
    {
        return $weeklyPlan->entries()
            ->where('dish_id', $dish->id)
            ->where('weekday', '<', $weekday)
            ->exists();
    }
}
