<?php

use App\Actions\GroceryLists\AddManualGroceryItem;
use App\Actions\WeeklyPlans\ScheduleWeeklyPlanEntry;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\ProteinCategory;
use App\WeeklyPlanEntrySlot;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('verified users without a family are redirected to family setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('families.index'));
});

test('unverified users are redirected to the email verification notice', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('dashboard data is scoped to the active family', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'UTC'));

    $user = User::factory()->create();
    $activeFamily = Family::factory()->for($user, 'head')->create(['name' => 'Visible Family', 'timezone' => 'UTC']);
    $otherFamily = Family::factory()->for($user, 'head')->create(['name' => 'Other Family', 'timezone' => 'UTC']);
    $visiblePlan = $activeFamily->weeklyPlans()->create(['week_start_date' => '2026-06-22']);
    $otherPlan = $otherFamily->weeklyPlans()->create(['week_start_date' => '2026-06-22']);

    app(ScheduleWeeklyPlanEntry::class)->execute(
        $user,
        $visiblePlan,
        1,
        WeeklyPlanEntrySlot::Main,
        dish: dashboardDish($activeFamily, 'Visible Dinner', ProteinCategory::Beef),
    );
    app(ScheduleWeeklyPlanEntry::class)->execute(
        $user,
        $otherPlan,
        1,
        WeeklyPlanEntrySlot::Main,
        dish: dashboardDish($otherFamily, 'Private Dinner', ProteinCategory::Poultry),
    );

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSet('activeFamilyId', $activeFamily->id)
        ->assertSee('Active family: Visible Family')
        ->assertSee('Visible Dinner')
        ->assertDontSee('Private Dinner');

    Carbon::setTestNow();
});

test('grocery progress counts active unchecked and total items', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'UTC'));

    $user = User::factory()->create();
    $this->actingAs($user);
    $family = Family::factory()->for($user, 'head')->create(['timezone' => 'UTC']);
    $plan = $family->weeklyPlans()->create(['week_start_date' => '2026-06-22']);
    $dish = dashboardDish($family, 'Grocery Dinner', ProteinCategory::Beef, ['Apples']);
    app(ScheduleWeeklyPlanEntry::class)->execute($user, $plan, 1, WeeklyPlanEntrySlot::Main, dish: $dish);
    app(AddManualGroceryItem::class)->execute($plan->groceryList, 'Napkins');

    $plan->groceryList->items()->where('normalized_name', 'apples')->update(['is_checked' => true]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSee('2 unchecked of 3 active items');

    Carbon::setTestNow();
});

test('dashboard defaults to the current week on weekdays and the upcoming week on weekends in the family timezone', function () {
    $user = User::factory()->create();
    Family::factory()->for($user, 'head')->create(['timezone' => 'Pacific/Honolulu']);

    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'Pacific/Honolulu'));

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSet('weekStartDate', '2026-06-22');

    Carbon::setTestNow(Carbon::parse('2026-06-27 12:00:00', 'Pacific/Honolulu'));

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSet('weekStartDate', '2026-06-29');

    Carbon::setTestNow();
});

test('checklist progress reflects active dish categories', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'UTC'));

    $user = User::factory()->create();
    $family = Family::factory()->for($user, 'head')->create(['timezone' => 'UTC']);
    dashboardDish($family, 'Beef One', ProteinCategory::Beef);
    dashboardDish($family, 'Beef Two', ProteinCategory::Beef);
    dashboardDish($family, 'Poultry One', ProteinCategory::Poultry);
    dashboardDish($family, 'Archived Fish', ProteinCategory::Fish)->update(['archived_at' => now()]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSee('Dish collection checklist')
        ->assertSeeInOrder(['Beef', 'Ready', '2 active dishes'])
        ->assertSeeInOrder(['Poultry', '1/2', '1 active dish'])
        ->assertSeeInOrder(['Fish', '0/2', '0 active dishes']);

    Carbon::setTestNow();
});

test('checklist dismissal is stored per member and family', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'UTC'));

    $user = User::factory()->create();
    $otherMember = User::factory()->create();
    $family = Family::factory()->for($user, 'head')->create(['timezone' => 'UTC']);
    $family->members()->attach($otherMember);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSee('Dish collection checklist')
        ->call('dismissChecklist')
        ->assertDontSee('Dish collection checklist');

    expect($family->members()->whereKey($user->id)->first()->pivot->onboarding_checklist_dismissed_at)->not->toBeNull()
        ->and($family->members()->whereKey($otherMember->id)->first()->pivot->onboarding_checklist_dismissed_at)->toBeNull();

    Carbon::setTestNow();
});

test('switching families refreshes all dashboard content', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'UTC'));

    $user = User::factory()->create();
    $firstFamily = Family::factory()->for($user, 'head')->create(['name' => 'First Family', 'timezone' => 'UTC']);
    $secondFamily = Family::factory()->for($user, 'head')->create(['name' => 'Second Family', 'timezone' => 'UTC']);
    $firstPlan = $firstFamily->weeklyPlans()->create(['week_start_date' => '2026-06-22']);
    $secondPlan = $secondFamily->weeklyPlans()->create(['week_start_date' => '2026-06-22']);

    app(ScheduleWeeklyPlanEntry::class)->execute(
        $user,
        $firstPlan,
        1,
        WeeklyPlanEntrySlot::Main,
        dish: dashboardDish($firstFamily, 'First Dinner', ProteinCategory::Beef),
    );
    app(ScheduleWeeklyPlanEntry::class)->execute(
        $user,
        $secondPlan,
        1,
        WeeklyPlanEntrySlot::Main,
        dish: dashboardDish($secondFamily, 'Second Dinner', ProteinCategory::Poultry),
    );

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSee('Active family: First Family')
        ->assertSee('First Dinner')
        ->set('selectedFamilyId', $secondFamily->id)
        ->call('switchFamily')
        ->assertSet('activeFamilyId', $secondFamily->id)
        ->assertSee('Active family: Second Family')
        ->assertSee('Second Dinner')
        ->assertDontSee('First Dinner');

    Carbon::setTestNow();
});

/**
 * @param  list<string>  $extraIngredientNames
 */
function dashboardDish(Family $family, string $name, ProteinCategory $category, array $extraIngredientNames = []): Dish
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
            'normalized_name' => str($ingredientName)->lower()->toString(),
            'protein_category' => null,
        ]);

        $dish->ingredients()->attach($ingredient, ['is_main_protein' => false]);
    }

    return $dish;
}
