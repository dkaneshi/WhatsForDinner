<?php

use App\Actions\WeeklyPlans\RegenerateWeeklyPlanSuggestions;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanSuggestion;
use App\ProteinCategory;
use App\WeeklyPlanEntrySlot;
use Livewire\Livewire;

function suggestionDish(Family $family, ProteinCategory $category, string $name): Dish
{
    $mainProtein = Ingredient::factory()->for($family)->create([
        'name' => $name.' Protein',
        'normalized_name' => strtolower($name).' protein',
        'protein_category' => $category,
    ]);
    $dish = Dish::factory()->for($family)->create([
        'name' => $name,
        'normalized_name' => strtolower($name),
    ]);

    $dish->ingredients()->attach($mainProtein, ['is_main_protein' => true]);

    return $dish;
}

/**
 * @return array<string, list<Dish>>
 */
function suggestionDishSet(Family $family, int $perCategory = 2): array
{
    $dishes = [];

    foreach (ProteinCategory::cases() as $category) {
        $dishes[$category->value] = [];

        for ($index = 1; $index <= $perCategory; $index++) {
            $dishes[$category->value][] = suggestionDish($family, $category, $category->label().' '.$index);
        }
    }

    return $dishes;
}

function suggestionPlan(Family $family, string $weekStart = '2026-06-29'): WeeklyPlan
{
    return WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => $weekStart,
    ]);
}

test('a sufficiently populated collection returns exactly two dishes per category', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    suggestionDishSet($family, perCategory: 3);
    $plan = suggestionPlan($family);

    $suggestions = app(RegenerateWeeklyPlanSuggestions::class)->execute($head, $plan);
    $categoryCounts = $suggestions->countBy(fn (Dish $dish): string => $dish->proteinCategory()->value);

    expect($suggestions)->toHaveCount(10);

    foreach (ProteinCategory::cases() as $category) {
        expect($categoryCounts[$category->value])->toBe(2);
    }
});

test('no suggestion contains an archived scheduled or duplicate dish', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $dishes = suggestionDishSet($family, perCategory: 3);
    $plan = suggestionPlan($family);
    $archivedDish = $dishes[ProteinCategory::Beef->value][0];
    $scheduledDish = $dishes[ProteinCategory::Poultry->value][0];

    $archivedDish->update(['archived_at' => now()]);
    WeeklyPlanEntry::factory()->for($plan)->for($scheduledDish)->create([
        'weekday' => 1,
        'slot' => WeeklyPlanEntrySlot::Main,
    ]);

    $suggestions = app(RegenerateWeeklyPlanSuggestions::class)->execute($head, $plan);
    $suggestionIds = $suggestions->pluck('id')->all();

    expect($suggestionIds)->not->toContain($archivedDish->id)
        ->and($suggestionIds)->not->toContain($scheduledDish->id)
        ->and($suggestionIds)->toHaveCount(count(array_unique($suggestionIds)));
});

test('main and alternative history both affect recent dish preference', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $recentMain = suggestionDish($family, ProteinCategory::Beef, 'Recent Main Beef');
    $recentAlternative = suggestionDish($family, ProteinCategory::Beef, 'Recent Alternative Beef');
    $freshBeefOne = suggestionDish($family, ProteinCategory::Beef, 'Fresh Beef One');
    $freshBeefTwo = suggestionDish($family, ProteinCategory::Beef, 'Fresh Beef Two');

    foreach (ProteinCategory::cases() as $category) {
        if ($category === ProteinCategory::Beef) {
            continue;
        }

        suggestionDish($family, $category, $category->label().' Fresh One');
        suggestionDish($family, $category, $category->label().' Fresh Two');
    }

    $currentPlan = suggestionPlan($family, '2026-06-29');
    $priorPlan = suggestionPlan($family, '2026-06-22');

    WeeklyPlanEntry::factory()->for($priorPlan)->for($recentMain)->create([
        'weekday' => 1,
        'slot' => WeeklyPlanEntrySlot::Main,
    ]);
    WeeklyPlanEntry::factory()->for($priorPlan)->for($recentAlternative)->alternative()->create([
        'weekday' => 1,
    ]);

    $suggestionIds = app(RegenerateWeeklyPlanSuggestions::class)
        ->execute($head, $currentPlan)
        ->pluck('id')
        ->all();

    expect($suggestionIds)->not->toContain($recentMain->id)
        ->and($suggestionIds)->not->toContain($recentAlternative->id)
        ->and($suggestionIds)->toContain($freshBeefOne->id)
        ->and($suggestionIds)->toContain($freshBeefTwo->id);
});

test('multiple occurrences of one dish count once in history', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $recentDish = suggestionDish($family, ProteinCategory::Pork, 'Repeated Recent Pork');
    suggestionDishSet($family, perCategory: 3);
    $currentPlan = suggestionPlan($family, '2026-06-29');
    $priorPlan = suggestionPlan($family, '2026-06-22');

    WeeklyPlanEntry::factory()->for($priorPlan)->for($recentDish)->create([
        'weekday' => 1,
        'slot' => WeeklyPlanEntrySlot::Main,
    ]);
    WeeklyPlanEntry::factory()->for($priorPlan)->for($recentDish)->create([
        'weekday' => 2,
        'slot' => WeeklyPlanEntrySlot::Main,
    ]);

    $suggestions = app(RegenerateWeeklyPlanSuggestions::class)->execute($head, $currentPlan);

    expect($suggestions)->toHaveCount(10)
        ->and($suggestions->pluck('id')->all())->not->toContain($recentDish->id);
});

test('category shortages still produce the largest possible unique set', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $plan = suggestionPlan($family);

    foreach (range(1, 4) as $index) {
        suggestionDish($family, ProteinCategory::Beef, 'Beef Shortage '.$index);
    }

    suggestionDish($family, ProteinCategory::Poultry, 'Only Poultry');
    suggestionDish($family, ProteinCategory::Pork, 'Only Pork');
    suggestionDish($family, ProteinCategory::Fish, 'Only Fish');
    suggestionDish($family, ProteinCategory::Vegetable, 'Only Vegetable');

    $suggestions = app(RegenerateWeeklyPlanSuggestions::class)->execute($head, $plan);

    expect($suggestions)->toHaveCount(8)
        ->and($suggestions->pluck('id')->unique())->toHaveCount(8);
});

test('collections smaller than ten never receive duplicate filler', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $plan = suggestionPlan($family);

    suggestionDish($family, ProteinCategory::Beef, 'Small Beef');
    suggestionDish($family, ProteinCategory::Poultry, 'Small Poultry');
    suggestionDish($family, ProteinCategory::Fish, 'Small Fish');

    $suggestions = app(RegenerateWeeklyPlanSuggestions::class)->execute($head, $plan);

    expect($suggestions)->toHaveCount(3)
        ->and($suggestions->pluck('id')->unique())->toHaveCount(3);
});

test('regeneration replaces suggestions without changing scheduled entries', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $dishes = suggestionDishSet($family, perCategory: 3);
    $plan = suggestionPlan($family);
    $scheduledDish = $dishes[ProteinCategory::Beef->value][0];
    $entry = WeeklyPlanEntry::factory()->for($plan)->for($scheduledDish)->create([
        'weekday' => 1,
        'slot' => WeeklyPlanEntrySlot::Main,
    ]);
    WeeklyPlanSuggestion::factory()->for($plan)->for($scheduledDish)->create([
        'position' => 1,
    ]);

    app(RegenerateWeeklyPlanSuggestions::class)->execute($head, $plan);

    expect($entry->fresh())->not->toBeNull()
        ->and($plan->suggestions()->where('dish_id', $scheduledDish->id)->exists())->toBeFalse()
        ->and($plan->entries()->count())->toBe(1);
});

test('first visit creates initial suggestions for editable weeks', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create(['timezone' => 'UTC']);
    suggestionDishSet($family, perCategory: 2);

    Livewire::actingAs($head)
        ->test('pages::weekly-plan')
        ->assertSee('Suggestions');

    expect($family->weeklyPlans()->sole()->suggestions()->count())->toBe(10);
});
