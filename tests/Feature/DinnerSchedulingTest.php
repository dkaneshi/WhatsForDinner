<?php

use App\Actions\WeeklyPlans\ScheduleWeeklyPlanEntry;
use App\Models\Dish;
use App\Models\Family;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use App\WeeklyPlanEntrySlot;
use App\WeeklyPlanSpecialEntry;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function schedulingFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00', 'UTC'));

    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create(['timezone' => 'UTC']);
    $plan = WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => '2026-06-22',
    ]);

    return [$head, $family, $plan];
}

test('a second main or alternative entry for the same day is rejected', function () {
    [$head, $family, $plan] = schedulingFixture();
    $firstDish = Dish::factory()->for($family)->create();
    $secondDish = Dish::factory()->for($family)->create();
    $action = app(ScheduleWeeklyPlanEntry::class);

    $action->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $firstDish);

    expect(fn () => $action->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $secondDish))
        ->toThrow(ValidationException::class);

    $action->execute($head, $plan, 1, WeeklyPlanEntrySlot::Alternative, dish: $secondDish);

    expect(fn () => $action->execute($head, $plan, 1, WeeklyPlanEntrySlot::Alternative, dish: $firstDish))
        ->toThrow(ValidationException::class);

    Carbon::setTestNow();
});

test('a dish can be scheduled across nonconsecutive weekdays and later occurrences default to leftovers', function () {
    [$head, $family, $plan] = schedulingFixture();
    $dish = Dish::factory()->for($family)->create(['name' => 'Meatloaf']);
    $action = app(ScheduleWeeklyPlanEntry::class);

    $monday = $action->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);
    $thursday = $action->execute($head, $plan, 4, WeeklyPlanEntrySlot::Main, dish: $dish);

    expect($monday->is_leftovers)->toBeFalse()
        ->and($thursday->is_leftovers)->toBeTrue()
        ->and($thursday->label())->toBe('Leftovers: Meatloaf');

    Carbon::setTestNow();
});

test('a member can remove the leftovers designation', function () {
    [$head, $family, $plan] = schedulingFixture();
    $dish = Dish::factory()->for($family)->create(['name' => 'Shoyu Chicken']);
    WeeklyPlanEntry::factory()->for($plan)->for($dish)->create([
        'weekday' => 1,
        'slot' => WeeklyPlanEntrySlot::Main,
        'is_leftovers' => false,
    ]);
    $leftovers = WeeklyPlanEntry::factory()->for($plan)->for($dish)->create([
        'weekday' => 3,
        'slot' => WeeklyPlanEntrySlot::Main,
        'is_leftovers' => true,
    ]);

    Livewire::actingAs($head)
        ->test('pages::weekly-plan')
        ->call('markFresh', $leftovers->id)
        ->assertHasNoErrors();

    expect($leftovers->fresh()->is_leftovers)->toBeFalse();

    Carbon::setTestNow();
});

test('special entries are main entries without alternatives or ingredients', function () {
    [$head, $family, $plan] = schedulingFixture();
    $dish = Dish::factory()->for($family)->create();
    $action = app(ScheduleWeeklyPlanEntry::class);

    $entry = $action->execute(
        user: $head,
        weeklyPlan: $plan,
        weekday: 2,
        slot: WeeklyPlanEntrySlot::Main,
        specialEntry: WeeklyPlanSpecialEntry::EatOut,
    );

    expect($entry->dish_id)->toBeNull()
        ->and($entry->special_entry)->toBe(WeeklyPlanSpecialEntry::EatOut)
        ->and($entry->label())->toBe('Eat Out');

    expect(fn () => $action->execute($head, $plan, 2, WeeklyPlanEntrySlot::Alternative, dish: $dish))
        ->toThrow(ValidationException::class);

    expect(fn () => $action->execute(
        user: $head,
        weeklyPlan: $plan,
        weekday: 3,
        slot: WeeklyPlanEntrySlot::Alternative,
        specialEntry: WeeklyPlanSpecialEntry::TvDinnerNight,
    ))->toThrow(ValidationException::class);

    Carbon::setTestNow();
});

test('five main entries including special entries complete the week', function () {
    [$head, $family, $plan] = schedulingFixture();
    $action = app(ScheduleWeeklyPlanEntry::class);
    $dish = Dish::factory()->for($family)->create();

    $action->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);
    $action->execute($head, $plan, 2, WeeklyPlanEntrySlot::Main, specialEntry: WeeklyPlanSpecialEntry::EatOut);
    $action->execute($head, $plan, 3, WeeklyPlanEntrySlot::Main, dish: $dish);
    $action->execute($head, $plan, 4, WeeklyPlanEntrySlot::Main, specialEntry: WeeklyPlanSpecialEntry::TvDinnerNight);

    expect($plan->isComplete())->toBeFalse();

    $action->execute($head, $plan, 5, WeeklyPlanEntrySlot::Main, dish: $dish, isLeftovers: false);

    expect($plan->isComplete())->toBeTrue();

    Carbon::setTestNow();
});
