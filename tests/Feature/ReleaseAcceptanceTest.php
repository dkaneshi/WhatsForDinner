<?php

use App\Actions\WeeklyPlans\ScheduleWeeklyPlanEntry;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\ProteinCategory;
use App\WeeklyPlanEntrySlot;
use Illuminate\Support\Carbon;

test('core release pages render with accessible mobile controls', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'Pacific/Honolulu'));

    $user = User::factory()->create();
    $family = Family::factory()->for($user, 'head')->create([
        'name' => 'Kaneshiro Family',
        'timezone' => 'Pacific/Honolulu',
    ]);

    releaseDish($family, 'Loco Moco', ProteinCategory::Beef, ['Rice', 'Eggs']);

    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Active family: Kaneshiro Family')
        ->assertSee('aria-label="Switch active family"', false)
        ->assertSee('aria-label="Plan dinners for this week"', false)
        ->assertSee('aria-label="Open grocery list"', false);

    $this->get(route('weekly-plans.show'))
        ->assertSuccessful()
        ->assertSee('Weekly plan')
        ->assertSee('aria-label="Go to previous planning week"', false)
        ->assertSee('aria-label="Add main dish for Monday"', false)
        ->assertSee('aria-label="Regenerate weekly dinner suggestions"', false);

    $this->get(route('grocery-lists.show'))
        ->assertSuccessful()
        ->assertSee('Grocery list')
        ->assertSee('aria-label="Go to previous grocery week"', false)
        ->assertSee('aria-label="Add manual grocery item"', false);

    Carbon::setTestNow();
});

test('scheduled dinners complete the week and create groceries quickly', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'Pacific/Honolulu'));

    $startedAt = microtime(true);
    $user = User::factory()->create();
    $family = Family::factory()->for($user, 'head')->create(['timezone' => 'Pacific/Honolulu']);
    $plan = WeeklyPlan::factory()->for($family)->create(['week_start_date' => '2026-06-22']);
    $schedule = app(ScheduleWeeklyPlanEntry::class);

    collect([
        [1, 'Monday Beef', ProteinCategory::Beef, ['Rice']],
        [2, 'Tuesday Chicken', ProteinCategory::Poultry, ['Noodles']],
        [3, 'Wednesday Pork', ProteinCategory::Pork, ['Cabbage']],
        [4, 'Thursday Fish', ProteinCategory::Fish, ['Lemons']],
        [5, 'Friday Veg', ProteinCategory::Vegetable, ['Tofu']],
    ])->each(function (array $dinner) use ($family, $plan, $schedule, $user): void {
        [$weekday, $name, $category, $ingredients] = $dinner;

        $schedule->execute(
            $user,
            $plan,
            $weekday,
            WeeklyPlanEntrySlot::Main,
            dish: releaseDish($family, $name, $category, $ingredients),
        );
    });

    $elapsedSeconds = microtime(true) - $startedAt;

    expect($plan->fresh()->isComplete())->toBeTrue()
        ->and($plan->groceryList->items()->where('is_suppressed', false)->count())->toBe(10)
        ->and($elapsedSeconds)->toBeLessThan(10.0);

    Carbon::setTestNow();
});

/**
 * @param  list<string>  $extraIngredientNames
 */
function releaseDish(Family $family, string $name, ProteinCategory $category, array $extraIngredientNames = []): Dish
{
    $dish = Dish::factory()->for($family)->create([
        'name' => $name,
        'normalized_name' => str($name)->lower()->toString(),
    ]);
    $mainProtein = Ingredient::factory()->for($family)->create([
        'name' => $name.' Protein',
        'normalized_name' => str($name.' Protein')->lower()->toString(),
        'protein_category' => $category,
    ]);

    $dish->ingredients()->attach($mainProtein, ['is_main_protein' => true]);

    foreach ($extraIngredientNames as $ingredientName) {
        $ingredient = Ingredient::factory()->for($family)->create([
            'name' => $ingredientName,
            'normalized_name' => str($ingredientName)->squish()->lower()->toString(),
            'protein_category' => null,
        ]);

        $dish->ingredients()->attach($ingredient, ['is_main_protein' => false]);
    }

    return $dish;
}
