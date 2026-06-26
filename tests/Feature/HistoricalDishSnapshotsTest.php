<?php

use App\Actions\Dishes\ArchiveDish;
use App\Actions\Dishes\UpdateDish;
use App\Actions\Ingredients\UpdateIngredient;
use App\Actions\WeeklyPlans\ScheduleWeeklyPlanEntry;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\ProteinCategory;
use App\WeeklyPlanEntrySlot;
use Illuminate\Support\Carbon;

/**
 * @return array{User, Family}
 */
function historicalSnapshotFamily(): array
{
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create(['timezone' => 'UTC']);

    return [$head, $family];
}

/**
 * @return array{Dish, Ingredient, Ingredient}
 */
function historicalSnapshotDish(Family $family, string $name = 'Meatloaf'): array
{
    $mainProtein = Ingredient::factory()->for($family)->create([
        'name' => 'Hamburger',
        'normalized_name' => 'hamburger',
        'protein_category' => ProteinCategory::Beef,
    ]);
    $binder = Ingredient::factory()->for($family)->create([
        'name' => 'Breadcrumbs',
        'normalized_name' => 'breadcrumbs',
        'protein_category' => null,
    ]);
    $dish = Dish::factory()->for($family)->create([
        'name' => $name,
        'normalized_name' => str($name)->lower()->toString(),
        'note' => 'Original note',
    ]);

    $dish->ingredients()->attach($mainProtein, ['is_main_protein' => true]);
    $dish->ingredients()->attach($binder, ['is_main_protein' => false]);

    return [$dish, $mainProtein, $binder];
}

function historicalSnapshotPlan(Family $family, string $weekStart): WeeklyPlan
{
    return WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => $weekStart,
    ]);
}

test('scheduled entries store dish name note and ingredients when created', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00', 'UTC'));

    [$head, $family] = historicalSnapshotFamily();
    [$dish] = historicalSnapshotDish($family);
    $plan = historicalSnapshotPlan($family, '2026-06-22');

    $entry = app(ScheduleWeeklyPlanEntry::class)->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);

    expect($entry->dish_snapshot_name)->toBe('Meatloaf')
        ->and($entry->dish_snapshot_note)->toBe('Original note')
        ->and($entry->ingredientNames())->toBe(['Breadcrumbs', 'Hamburger']);

    Carbon::setTestNow();
});

test('editing a dish updates current and future scheduled displays', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00', 'UTC'));

    [$head, $family] = historicalSnapshotFamily();
    [$dish, $mainProtein] = historicalSnapshotDish($family);
    $newIngredient = Ingredient::factory()->for($family)->create([
        'name' => 'Ketchup',
        'normalized_name' => 'ketchup',
    ]);
    $currentPlan = historicalSnapshotPlan($family, '2026-06-22');
    $futurePlan = historicalSnapshotPlan($family, '2026-06-29');
    $action = app(ScheduleWeeklyPlanEntry::class);

    $currentEntry = $action->execute($head, $currentPlan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);
    $futureEntry = $action->execute($head, $futurePlan, 2, WeeklyPlanEntrySlot::Main, dish: $dish);

    app(UpdateDish::class)->execute($head, $dish, [
        'name' => 'Family Meatloaf',
        'note' => 'Updated note',
        'ingredient_ids' => [$mainProtein->id, $newIngredient->id],
        'main_protein_ingredient_id' => $mainProtein->id,
    ]);

    expect($currentEntry->fresh()->label())->toBe('Family Meatloaf')
        ->and($currentEntry->fresh()->dish_snapshot_note)->toBe('Updated note')
        ->and($currentEntry->fresh()->ingredientNames())->toBe(['Hamburger', 'Ketchup'])
        ->and($futureEntry->fresh()->label())->toBe('Family Meatloaf')
        ->and($futureEntry->fresh()->ingredientNames())->toBe(['Hamburger', 'Ketchup']);

    Carbon::setTestNow();
});

test('editing an ingredient updates current scheduled snapshot ingredients', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00', 'UTC'));

    [$head, $family] = historicalSnapshotFamily();
    [$dish, $mainProtein, $binder] = historicalSnapshotDish($family);
    $plan = historicalSnapshotPlan($family, '2026-06-22');
    $entry = app(ScheduleWeeklyPlanEntry::class)->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);

    app(UpdateIngredient::class)->execute($head, $binder, [
        'name' => 'Panko',
        'protein_category' => null,
    ]);

    expect($entry->fresh()->ingredientNames())->toBe(['Hamburger', 'Panko']);

    app(UpdateIngredient::class)->execute($head, $mainProtein, [
        'name' => 'Ground Beef',
        'protein_category' => ProteinCategory::Beef->value,
    ]);

    expect($entry->fresh()->ingredientNames())->toBe(['Ground Beef', 'Panko']);

    Carbon::setTestNow();
});

test('editing a dish does not change a past scheduled snapshot', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00', 'UTC'));

    [$head, $family] = historicalSnapshotFamily();
    [$dish, $mainProtein] = historicalSnapshotDish($family);
    $pastPlan = historicalSnapshotPlan($family, '2026-06-22');
    $pastEntry = app(ScheduleWeeklyPlanEntry::class)->execute($head, $pastPlan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);

    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'UTC'));

    $newIngredient = Ingredient::factory()->for($family)->create([
        'name' => 'Ketchup',
        'normalized_name' => 'ketchup',
    ]);

    app(UpdateDish::class)->execute($head, $dish, [
        'name' => 'Family Meatloaf',
        'note' => 'Updated note',
        'ingredient_ids' => [$mainProtein->id, $newIngredient->id],
        'main_protein_ingredient_id' => $mainProtein->id,
    ]);

    expect($pastEntry->fresh()->label())->toBe('Meatloaf')
        ->and($pastEntry->fresh()->dish_snapshot_note)->toBe('Original note')
        ->and($pastEntry->fresh()->ingredientNames())->toBe(['Breadcrumbs', 'Hamburger']);

    Carbon::setTestNow();
});

test('archiving a dish does not erase historical snapshots', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00', 'UTC'));

    [$head, $family] = historicalSnapshotFamily();
    [$dish] = historicalSnapshotDish($family, 'Shoyu Chicken');
    $plan = historicalSnapshotPlan($family, '2026-06-22');
    $entry = app(ScheduleWeeklyPlanEntry::class)->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);

    app(ArchiveDish::class)->execute($head, $dish);

    expect($entry->fresh()->label())->toBe('Shoyu Chicken')
        ->and($entry->fresh()->ingredientNames())->toBe(['Breadcrumbs', 'Hamburger']);

    Carbon::setTestNow();
});
