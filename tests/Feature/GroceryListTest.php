<?php

use App\Actions\Dishes\UpdateDish;
use App\Actions\GroceryLists\AddManualGroceryItem;
use App\Actions\GroceryLists\ReconcileGroceryList;
use App\Actions\GroceryLists\UpdateGroceryItemState;
use App\Actions\Ingredients\UpdateIngredient;
use App\Actions\WeeklyPlans\RemoveWeeklyPlanEntry;
use App\Actions\WeeklyPlans\ScheduleWeeklyPlanEntry;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\ProteinCategory;
use App\WeeklyPlanEntrySlot;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * @return array{User, Family, WeeklyPlan}
 */
function groceryFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00', 'UTC'));

    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create(['timezone' => 'UTC']);
    $plan = WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => '2026-06-22',
    ]);

    return [$head, $family, $plan];
}

/**
 * @param  list<string>  $ingredientNames
 */
function groceryDish(Family $family, string $name, array $ingredientNames): Dish
{
    $dish = Dish::factory()->for($family)->create([
        'name' => $name,
        'normalized_name' => str($name)->lower()->toString(),
    ]);

    foreach ($ingredientNames as $index => $ingredientName) {
        $normalizedName = str($ingredientName)->squish()->lower()->toString();
        $ingredient = $family->ingredients()->firstOrCreate(
            ['normalized_name' => $normalizedName],
            [
                'name' => $ingredientName,
                'protein_category' => $index === 0 ? ProteinCategory::Beef : null,
            ],
        );

        $dish->ingredients()->attach($ingredient, ['is_main_protein' => $index === 0]);
    }

    return $dish;
}

test('weekly grocery list combines main and alternative dishes and merges duplicate ingredients', function () {
    [$head, $family, $plan] = groceryFixture();
    $meatloaf = groceryDish($family, 'Meatloaf', ['Hamburger', 'Eggs']);
    $breakfast = groceryDish($family, 'Breakfast', ['Bacon', 'Eggs']);
    $schedule = app(ScheduleWeeklyPlanEntry::class);

    $schedule->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $meatloaf);
    $schedule->execute($head, $plan, 1, WeeklyPlanEntrySlot::Alternative, dish: $breakfast);

    $items = $plan->groceryList->items()->orderBy('name')->get();
    $eggs = $items->firstWhere('normalized_name', 'eggs');

    expect($items->pluck('name')->all())->toBe(['Bacon', 'Eggs', 'Hamburger'])
        ->and($eggs->source_labels)->toBe(['Breakfast', 'Meatloaf'])
        ->and($eggs->source_entry_ids)->toHaveCount(2);

    Carbon::setTestNow();
});

test('duplicate ingredient names merge ignoring capitalization and extra whitespace', function () {
    [$head, $family, $plan] = groceryFixture();
    $firstDish = groceryDish($family, 'First Dinner', ['Chicken']);
    $secondDish = groceryDish($family, 'Second Dinner', ['Pork']);
    $schedule = app(ScheduleWeeklyPlanEntry::class);

    $firstEntry = $schedule->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $firstDish);
    $secondEntry = $schedule->execute($head, $plan, 2, WeeklyPlanEntrySlot::Main, dish: $secondDish);

    $firstEntry->update(['dish_snapshot_ingredients' => [['name' => ' Eggs ', 'is_main_protein' => false]]]);
    $secondEntry->update(['dish_snapshot_ingredients' => [['name' => 'eggs', 'is_main_protein' => false]]]);

    app(ReconcileGroceryList::class)->execute($plan);

    expect($plan->groceryList->items()->where('normalized_name', 'eggs')->count())->toBe(1)
        ->and($plan->groceryList->items()->sole()->name)->toBe('eggs');

    Carbon::setTestNow();
});

test('recalculation preserves checked retained items adds new unchecked items and removes obsolete generated items', function () {
    [$head, $family, $plan] = groceryFixture();
    $meatloaf = groceryDish($family, 'Meatloaf', ['Hamburger', 'Eggs']);
    $breakfast = groceryDish($family, 'Breakfast', ['Bacon', 'Eggs']);
    $schedule = app(ScheduleWeeklyPlanEntry::class);

    $meatloafEntry = $schedule->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $meatloaf);
    $eggs = $plan->groceryList->items()->where('normalized_name', 'eggs')->sole();
    $eggs->update(['is_checked' => true]);

    $schedule->execute($head, $plan, 2, WeeklyPlanEntrySlot::Main, dish: $breakfast);

    expect($eggs->fresh()->is_checked)->toBeTrue()
        ->and($plan->groceryList->items()->where('normalized_name', 'bacon')->sole()->is_checked)->toBeFalse();

    app(RemoveWeeklyPlanEntry::class)->execute($head, $meatloafEntry);

    expect($plan->groceryList->items()->where('normalized_name', 'hamburger')->exists())->toBeFalse()
        ->and($eggs->fresh()->is_checked)->toBeTrue()
        ->and($eggs->fresh()->source_labels)->toBe(['Breakfast']);

    Carbon::setTestNow();
});

test('manual items survive recalculation and do not become reusable ingredients', function () {
    [$head, $family, $plan] = groceryFixture();
    $this->actingAs($head);
    $dish = groceryDish($family, 'Meatloaf', ['Hamburger']);
    app(ScheduleWeeklyPlanEntry::class)->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);
    $groceryList = $plan->groceryList;
    $ingredientCount = $family->ingredients()->count();

    app(AddManualGroceryItem::class)->execute($groceryList, ' Paper Plates ');
    app(ReconcileGroceryList::class)->execute($plan);

    $manualItem = $groceryList->items()->where('normalized_name', 'paper plates')->sole();

    expect($manualItem->is_manual)->toBeTrue()
        ->and($manualItem->name)->toBe('Paper Plates')
        ->and($family->ingredients()->count())->toBe($ingredientCount);

    Carbon::setTestNow();
});

test('relevant dish and ingredient edits automatically recalculate active grocery lists', function () {
    [$head, $family, $plan] = groceryFixture();
    $dish = groceryDish($family, 'Meatloaf', ['Hamburger', 'Breadcrumbs']);
    $entry = app(ScheduleWeeklyPlanEntry::class)->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);
    $hamburger = $family->ingredients()->where('normalized_name', 'hamburger')->sole();
    $ketchup = Ingredient::factory()->for($family)->create([
        'name' => 'Ketchup',
        'normalized_name' => 'ketchup',
        'protein_category' => null,
    ]);

    app(UpdateDish::class)->execute($head, $dish, [
        'name' => 'Meatloaf',
        'note' => null,
        'ingredient_ids' => [$hamburger->id, $ketchup->id],
        'main_protein_ingredient_id' => $hamburger->id,
    ]);

    expect($plan->groceryList->items()->where('normalized_name', 'breadcrumbs')->exists())->toBeFalse()
        ->and($plan->groceryList->items()->where('normalized_name', 'ketchup')->exists())->toBeTrue()
        ->and($entry->fresh()->ingredientNames())->toBe(['Hamburger', 'Ketchup']);

    app(UpdateIngredient::class)->execute($head, $ketchup, [
        'name' => 'Katsup',
        'protein_category' => null,
    ]);

    expect($plan->groceryList->items()->where('normalized_name', 'ketchup')->exists())->toBeFalse()
        ->and($plan->groceryList->items()->where('normalized_name', 'katsup')->exists())->toBeTrue();

    Carbon::setTestNow();
});

test('removing a generated item suppresses it for the week until restored', function () {
    [$head, $family, $plan] = groceryFixture();
    $this->actingAs($head);
    $dish = groceryDish($family, 'Meatloaf', ['Hamburger', 'Eggs']);
    app(ScheduleWeeklyPlanEntry::class)->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);
    $item = $plan->groceryList->items()->where('normalized_name', 'eggs')->sole();
    $state = app(UpdateGroceryItemState::class);

    $state->remove($item);
    app(ReconcileGroceryList::class)->execute($plan);

    expect($item->fresh()->is_suppressed)->toBeTrue()
        ->and($plan->groceryList->items()->where('is_suppressed', false)->pluck('normalized_name')->all())->toBe(['hamburger']);

    $state->restore($item->fresh());

    expect($item->fresh()->is_suppressed)->toBeFalse()
        ->and($item->fresh()->is_checked)->toBeFalse();

    Carbon::setTestNow();
});

test('grocery page shows unchecked alphabetical items before completed items', function () {
    [$head, $family, $plan] = groceryFixture();
    $this->actingAs($head);
    $dish = groceryDish($family, 'Dinner', ['Bananas', 'Apples']);
    app(ScheduleWeeklyPlanEntry::class)->execute($head, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);
    $plan->groceryList->items()->where('normalized_name', 'bananas')->update(['is_checked' => true]);

    Livewire::actingAs($head)
        ->test('pages::grocery-list')
        ->assertSeeInOrder(['Apples', 'Completed', 'Bananas'])
        ->assertSee('Measurements are intentionally omitted');

    Carbon::setTestNow();
});

test('grocery route renders the active family grocery list', function () {
    [$head, $family] = groceryFixture();

    $this->actingAs($head)
        ->get(route('grocery-lists.show'))
        ->assertSuccessful()
        ->assertSee('Grocery list')
        ->assertSee($family->name);

    Carbon::setTestNow();
});
