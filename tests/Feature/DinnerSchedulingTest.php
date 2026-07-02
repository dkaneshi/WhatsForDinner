<?php

use App\Actions\WeeklyPlans\ScheduleWeeklyPlanEntry;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
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
        'week_start_date' => '2026-06-21',
    ]);

    return [$head, $family, $plan];
}

test('weekend days are accepted on a weekend-enabled plan and rejected on a legacy plan', function () {
    [$head, $family, $plan] = schedulingFixture();
    $legacyPlan = WeeklyPlan::factory()->for($family)->legacy()->create([
        'week_start_date' => '2026-06-28',
    ]);
    $dish = Dish::factory()->for($family)->create();
    $action = app(ScheduleWeeklyPlanEntry::class);

    $saturday = $action->execute($head, $plan, 6, WeeklyPlanEntrySlot::Main, dish: $dish);
    $sunday = $action->execute($head, $plan, 7, WeeklyPlanEntrySlot::Main, dish: $dish);

    expect($saturday->weekday)->toBe(6)
        ->and($sunday->weekday)->toBe(7);

    expect(fn () => $action->execute($head, $legacyPlan, 6, WeeklyPlanEntrySlot::Main, dish: $dish))
        ->toThrow(ValidationException::class)
        ->and(fn () => $action->execute($head, $legacyPlan, 7, WeeklyPlanEntrySlot::Main, dish: $dish))
        ->toThrow(ValidationException::class);

    Carbon::setTestNow();
});

test('weekend days support a main, an alternative, and special nights on a 7-day plan', function () {
    [$head, $family, $plan] = schedulingFixture();
    $main = Dish::factory()->for($family)->create();
    $alternative = Dish::factory()->for($family)->create();
    $action = app(ScheduleWeeklyPlanEntry::class);

    $saturdayMain = $action->execute($head, $plan, 6, WeeklyPlanEntrySlot::Main, dish: $main);
    $saturdayAlt = $action->execute($head, $plan, 6, WeeklyPlanEntrySlot::Alternative, dish: $alternative);
    $sundaySpecial = $action->execute($head, $plan, 7, WeeklyPlanEntrySlot::Main, specialEntry: WeeklyPlanSpecialEntry::EatOut);

    expect($saturdayMain->slot)->toBe(WeeklyPlanEntrySlot::Main)
        ->and($saturdayAlt->slot)->toBe(WeeklyPlanEntrySlot::Alternative)
        ->and($sundaySpecial->special_entry)->toBe(WeeklyPlanSpecialEntry::EatOut);

    expect(fn () => $action->execute($head, $plan, 6, WeeklyPlanEntrySlot::Main, dish: $alternative))
        ->toThrow(ValidationException::class);

    Carbon::setTestNow();
});

test('leftovers default follows the Sunday-first order so Monday after Sunday is leftovers', function () {
    [$head, $family, $plan] = schedulingFixture();
    $dish = Dish::factory()->for($family)->create(['name' => 'Pot Roast']);
    $action = app(ScheduleWeeklyPlanEntry::class);

    $sunday = $action->execute($head, $plan, 7, WeeklyPlanEntrySlot::Main, dish: $dish);
    $monday = $action->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);

    expect($sunday->is_leftovers)->toBeFalse()
        ->and($monday->is_leftovers)->toBeTrue()
        ->and($monday->label())->toBe('Leftovers: Pot Roast');

    Carbon::setTestNow();
});

test('the planning grid renders seven Sunday-first days for a weekend plan', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-28 12:00:00', 'UTC'));

    $head = User::factory()->create();
    Family::factory()->for($head, 'head')->create(['timezone' => 'UTC']);

    Livewire::actingAs($head)
        ->test('pages::weekly-plan')
        ->assertSet('weekStartDate', '2026-06-28')
        ->assertSee('Week starts Sunday, 2026-06-28')
        ->assertSeeInOrder(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']);

    Carbon::setTestNow();
});

test('a legacy past plan renders five read-only Monday-through-Friday days', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-28 12:00:00', 'UTC'));

    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create(['timezone' => 'UTC']);
    // A pre-change plan stored on its original Monday start date.
    WeeklyPlan::factory()->for($family)->legacy()->create([
        'week_start_date' => '2026-06-15',
    ]);

    Livewire::actingAs($head)
        ->test('pages::weekly-plan', ['weekStart' => '2026-06-15'])
        ->assertSet('isPast', true)
        ->assertSee('Read-only history')
        ->assertSeeInOrder(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'])
        ->assertSee('wire:key="weekday-5"', false)
        ->assertDontSee('wire:key="weekday-6"', false)
        ->assertDontSee('wire:key="weekday-7"', false);

    Carbon::setTestNow();
});

test('completeness ignores weekend slots on a seven-day plan', function () {
    [$head, $family, $plan] = schedulingFixture();
    $action = app(ScheduleWeeklyPlanEntry::class);
    $dish = Dish::factory()->for($family)->create();

    foreach ([1, 2, 3, 4, 5] as $weekday) {
        $action->execute($head, $plan, $weekday, WeeklyPlanEntrySlot::Main, dish: $dish);
    }

    expect($plan->isComplete())->toBeTrue();

    // Leaving the weekend empty never blocks completion; filling it never breaks it.
    $action->execute($head, $plan, 6, WeeklyPlanEntrySlot::Main, dish: $dish);
    $action->execute($head, $plan, 7, WeeklyPlanEntrySlot::Main, dish: $dish);

    expect($plan->isComplete())->toBeTrue();

    Carbon::setTestNow();
});

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

test('removing the leftovers designation refreshes grocery source labels', function () {
    [$head, $family, $plan] = schedulingFixture();
    $dish = Dish::factory()->for($family)->create(['name' => 'Pot Roast']);
    $ingredient = Ingredient::factory()->for($family)->create();
    $dish->ingredients()->attach($ingredient, ['is_main_protein' => true]);
    $action = app(ScheduleWeeklyPlanEntry::class);

    $action->execute($head, $plan, 7, WeeklyPlanEntrySlot::Main, dish: $dish);
    $monday = $action->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);

    $item = $plan->groceryList->items()->firstOrFail();

    expect($item->source_labels)->toContain('Leftovers: Pot Roast');

    Livewire::actingAs($head)
        ->test('pages::weekly-plan')
        ->call('markFresh', $monday->id)
        ->assertHasNoErrors();

    expect($item->fresh()->source_labels)
        ->not->toContain('Leftovers: Pot Roast')
        ->toContain('Pot Roast');

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
