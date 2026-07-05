<?php

use App\Actions\Ingredients\CreateIngredient;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\ProteinCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/**
 * @return array{User, Family}
 */
function familyMember(): array
{
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();

    return [$head, $family];
}

test('a member can add a new ingredient inline while building a dish', function () {
    [$head, $family] = familyMember();

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->call('startCreating')
        ->set('newIngredientName', '  Fresh   Basil ')
        ->call('addIngredient')
        ->assertHasNoErrors()
        ->assertSet('newIngredientName', '')
        ->assertSet('newIngredientProteinCategory', '');

    $ingredient = Ingredient::query()->sole();

    expect($ingredient->name)->toBe('Fresh Basil')
        ->and($ingredient->normalized_name)->toBe('fresh basil')
        ->and($ingredient->protein_category)->toBeNull()
        ->and($ingredient->family->is($family))->toBeTrue();
});

test('an inline ingredient is auto-selected in the dish form', function () {
    [$head] = familyMember();

    $component = Livewire::actingAs($head)
        ->test('pages::dishes')
        ->call('startCreating')
        ->set('newIngredientName', 'Garlic')
        ->call('addIngredient');

    $ingredient = Ingredient::query()->sole();

    $component->assertSet('ingredientIds', [$ingredient->id]);
});

test('an inline ingredient with a protein category can be used as the main protein', function () {
    [$head] = familyMember();

    $component = Livewire::actingAs($head)
        ->test('pages::dishes')
        ->call('startCreating')
        ->set('newIngredientName', 'Ground Beef')
        ->set('newIngredientProteinCategory', ProteinCategory::Beef->value)
        ->call('addIngredient')
        ->assertHasNoErrors();

    $ingredient = Ingredient::query()->sole();

    expect($ingredient->protein_category)->toBe(ProteinCategory::Beef);

    $component
        ->set('name', 'Simple Burger')
        ->set('mainProteinIngredientId', $ingredient->id)
        ->call('saveDish')
        ->assertHasNoErrors();

    expect($ingredient->dishes()->sole()->mainProtein()->sole()->is($ingredient))->toBeTrue();
});

test('typing an existing name selects the existing ingredient without duplicating', function () {
    [$head, $family] = familyMember();
    $existing = Ingredient::factory()->for($family)->create([
        'name' => 'Onion',
        'normalized_name' => 'onion',
        'protein_category' => null,
    ]);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->call('startCreating')
        ->set('newIngredientName', '  ONION ')
        ->call('addIngredient')
        ->assertHasNoErrors()
        ->assertSet('ingredientIds', [$existing->id])
        ->assertSet('newIngredientName', '');

    expect(Ingredient::query()->count())->toBe(1);
});

test('an inline ingredient requires a valid name', function () {
    [$head] = familyMember();

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->call('startCreating')
        ->set('newIngredientName', '')
        ->call('addIngredient')
        ->assertHasErrors(['new_ingredient_name'])
        ->set('newIngredientName', str_repeat('a', 101))
        ->call('addIngredient')
        ->assertHasErrors(['new_ingredient_name']);

    expect(Ingredient::query()->exists())->toBeFalse();
});

test('an inline ingredient persists even when the dish form is cancelled', function () {
    [$head] = familyMember();

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->call('startCreating')
        ->set('newIngredientName', 'Paprika')
        ->call('addIngredient')
        ->call('resetForm')
        ->assertSet('ingredientIds', []);

    expect(Ingredient::query()->where('normalized_name', 'paprika')->exists())->toBeTrue();
});

test('inline ingredient creation is available when editing a dish', function () {
    [$head, $family] = familyMember();
    $mainProtein = Ingredient::factory()->for($family)->create([
        'name' => 'Chicken Thigh',
        'normalized_name' => 'chicken thigh',
        'protein_category' => ProteinCategory::Poultry,
    ]);
    $dish = Dish::factory()->for($family)->create(['name' => 'Roast Chicken']);
    $dish->ingredients()->attach($mainProtein, ['is_main_protein' => true]);

    $component = Livewire::actingAs($head)
        ->test('pages::dishes')
        ->call('startEditing', $dish->id)
        ->set('newIngredientName', 'Rosemary')
        ->call('addIngredient')
        ->assertHasNoErrors();

    $rosemary = Ingredient::query()->where('normalized_name', 'rosemary')->sole();

    $component->assertSet('ingredientIds', [$mainProtein->id, $rosemary->id]);
});

test('inline ingredient creation is rejected for a user outside the family', function () {
    $outsider = User::factory()->create();
    $family = Family::factory()->create();

    expect(fn () => app(CreateIngredient::class)->execute($outsider, $family, [
        'name' => 'Contraband',
    ]))->toThrow(AuthorizationException::class);

    expect(Ingredient::query()->where('normalized_name', 'contraband')->exists())->toBeFalse();
});
