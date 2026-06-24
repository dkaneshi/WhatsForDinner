<?php

use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\ProteinCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('users without a family are redirected to family setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertRedirect(route('families.index'));
});

test('every family member can manage ingredients', function () {
    $member = User::factory()->create();
    $family = Family::factory()->create();
    $family->members()->attach($member);
    $member->forceFill(['current_family_id' => $family->id])->save();

    Livewire::actingAs($member)
        ->test('pages::ingredients')
        ->set('name', 'Chicken')
        ->set('proteinCategory', ProteinCategory::Poultry->value)
        ->call('createIngredient')
        ->assertHasNoErrors()
        ->assertSee('Chicken');

    $ingredient = Ingredient::query()->sole();

    expect($ingredient->family->is($family))->toBeTrue()
        ->and($ingredient->protein_category)->toBe(ProteinCategory::Poultry);
});

test('capitalization and extra whitespace do not create duplicate ingredients', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();

    Livewire::actingAs($head)
        ->test('pages::ingredients')
        ->set('name', '  Green   Onions  ')
        ->call('createIngredient')
        ->assertHasNoErrors()
        ->set('name', 'green onions')
        ->call('createIngredient')
        ->assertHasErrors(['name']);

    $ingredient = Ingredient::query()->sole();

    expect($ingredient->name)->toBe('Green Onions')
        ->and($ingredient->normalized_name)->toBe('green onions');
});

test('a duplicate ingredient error clears after adding a different ingredient', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    Ingredient::factory()->for($family)->create([
        'name' => 'Garlic',
        'normalized_name' => 'garlic',
    ]);

    Livewire::actingAs($head)
        ->test('pages::ingredients')
        ->set('name', 'GARLIC')
        ->call('createIngredient')
        ->assertHasErrors(['name'])
        ->set('name', 'Ginger')
        ->call('createIngredient')
        ->assertHasNoErrors()
        ->assertSet('name', '');

    expect($family->ingredients()->orderBy('normalized_name')->pluck('normalized_name')->all())
        ->toBe(['garlic', 'ginger']);
});

test('different families may use the same normalized ingredient name', function () {
    $firstHead = User::factory()->create();
    $secondHead = User::factory()->create();
    $firstFamily = Family::factory()->for($firstHead, 'head')->create();
    $secondFamily = Family::factory()->for($secondHead, 'head')->create();

    Livewire::actingAs($firstHead)
        ->test('pages::ingredients')
        ->set('name', 'Garlic')
        ->call('createIngredient')
        ->assertHasNoErrors();

    Livewire::actingAs($secondHead)
        ->test('pages::ingredients')
        ->set('name', ' garlic ')
        ->call('createIngredient')
        ->assertHasNoErrors();

    expect(Ingredient::query()->where('normalized_name', 'garlic')->count())->toBe(2)
        ->and($firstFamily->ingredients()->count())->toBe(1)
        ->and($secondFamily->ingredients()->count())->toBe(1);
});

test('ingredient protein categories are optional and restricted to approved values', function () {
    $head = User::factory()->create();
    Family::factory()->for($head, 'head')->create();

    Livewire::actingAs($head)
        ->test('pages::ingredients')
        ->set('name', 'Salt')
        ->call('createIngredient')
        ->assertHasNoErrors()
        ->set('name', 'Mystery meat')
        ->set('proteinCategory', 'shellfish')
        ->call('createIngredient')
        ->assertHasErrors(['protein_category']);

    expect(Ingredient::query()->sole()->protein_category)->toBeNull();
});

test('every approved protein category can be stored', function (ProteinCategory $category) {
    $head = User::factory()->create();
    Family::factory()->for($head, 'head')->create();

    Livewire::actingAs($head)
        ->test('pages::ingredients')
        ->set('name', $category->label().' Ingredient')
        ->set('proteinCategory', $category->value)
        ->call('createIngredient')
        ->assertHasNoErrors();

    expect(Ingredient::query()->sole()->protein_category)->toBe($category);
})->with(ProteinCategory::cases());

test('renaming an ingredient updates every connected dish', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $ingredient = Ingredient::factory()->for($family)->create([
        'name' => 'Hamburger',
        'normalized_name' => 'hamburger',
        'protein_category' => ProteinCategory::Beef,
    ]);
    $activeDish = Dish::factory()->for($family)->create();
    $archivedDish = Dish::factory()->for($family)->archived()->create();
    $activeDish->ingredients()->attach($ingredient, ['is_main_protein' => true]);
    $archivedDish->ingredients()->attach($ingredient, ['is_main_protein' => true]);

    Livewire::actingAs($head)
        ->test('pages::ingredients')
        ->call('startEditing', $ingredient->id)
        ->assertSet('affectedDishCount', 2)
        ->set('editName', 'Ground Beef')
        ->set('editProteinCategory', ProteinCategory::Beef->value)
        ->call('updateIngredient')
        ->assertHasNoErrors();

    expect($activeDish->ingredients()->sole()->name)->toBe('Ground Beef')
        ->and($archivedDish->ingredients()->sole()->name)->toBe('Ground Beef')
        ->and($ingredient->refresh()->normalized_name)->toBe('ground beef');
});

test('changing a main protein category reclassifies connected dishes', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $ingredient = Ingredient::factory()->for($family)->create([
        'protein_category' => ProteinCategory::Beef,
    ]);
    $dish = Dish::factory()->for($family)->create();
    $dish->ingredients()->attach($ingredient, ['is_main_protein' => true]);

    expect($dish->proteinCategory())->toBe(ProteinCategory::Beef);

    Livewire::actingAs($head)
        ->test('pages::ingredients')
        ->call('startEditing', $ingredient->id)
        ->set('editProteinCategory', ProteinCategory::Vegetable->value)
        ->call('updateIngredient')
        ->assertHasNoErrors();

    expect($dish->proteinCategory())->toBe(ProteinCategory::Vegetable);
});

test('ingredients referenced by active or archived dishes cannot be deleted', function (bool $archived) {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $ingredient = Ingredient::factory()->for($family)->create();
    $dishFactory = Dish::factory()->for($family);
    $dish = $archived ? $dishFactory->archived()->create() : $dishFactory->create();
    $dish->ingredients()->attach($ingredient);

    Livewire::actingAs($head)
        ->test('pages::ingredients')
        ->call('deleteIngredient', $ingredient->id)
        ->assertHasErrors(['ingredient']);

    expect($ingredient->fresh())->not->toBeNull();
})->with([
    'active dish' => false,
    'archived dish' => true,
]);

test('an unreferenced ingredient can be deleted', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $ingredient = Ingredient::factory()->for($family)->create();

    Livewire::actingAs($head)
        ->test('pages::ingredients')
        ->call('deleteIngredient', $ingredient->id)
        ->assertHasNoErrors();

    expect($ingredient->fresh())->toBeNull();
});

test('ingredient records and actions are isolated to the active family', function () {
    $user = User::factory()->create();
    $activeFamily = Family::factory()->for($user, 'head')->create();
    $privateIngredient = Ingredient::factory()->create(['name' => 'Private Ingredient']);
    Ingredient::factory()->for($activeFamily)->create(['name' => 'Visible Ingredient']);

    $component = Livewire::actingAs($user)
        ->test('pages::ingredients')
        ->assertSee('Visible Ingredient')
        ->assertDontSee('Private Ingredient');

    expect(fn () => $component->call('startEditing', $privateIngredient->id))
        ->toThrow(ModelNotFoundException::class);

    expect($privateIngredient->fresh())->not->toBeNull();
});
