<?php

use App\Actions\Dishes\CreateDish;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\ProteinCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * @return array{Ingredient, Ingredient}
 */
function ingredientsForDish(Family $family, ProteinCategory $category = ProteinCategory::Beef): array
{
    $mainProtein = Ingredient::factory()->for($family)->create([
        'name' => $category->label().' Protein',
        'normalized_name' => $category->value.' protein',
        'protein_category' => $category,
    ]);
    $otherIngredient = Ingredient::factory()->for($family)->create([
        'name' => 'Other Ingredient',
        'normalized_name' => 'other ingredient',
        'protein_category' => null,
    ]);

    return [$mainProtein, $otherIngredient];
}

function attachValidDishIngredients(Dish $dish, Ingredient $mainProtein, ?Ingredient $otherIngredient = null): void
{
    $dish->ingredients()->attach($mainProtein, ['is_main_protein' => true]);

    if ($otherIngredient) {
        $dish->ingredients()->attach($otherIngredient, ['is_main_protein' => false]);
    }
}

test('users without a family are redirected to family setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dishes.index'))
        ->assertRedirect(route('families.index'));
});

test('every family member can create a valid dish', function () {
    $member = User::factory()->create();
    $family = Family::factory()->create();
    $family->members()->attach($member);
    $member->forceFill(['current_family_id' => $family->id])->save();
    [$mainProtein, $otherIngredient] = ingredientsForDish($family, ProteinCategory::Poultry);

    Livewire::actingAs($member)
        ->test('pages::dishes')
        ->set('name', '  Baked   Chicken ')
        ->set('note', 'Family favorite')
        ->set('ingredientIds', [$mainProtein->id, $otherIngredient->id])
        ->set('mainProteinIngredientId', $mainProtein->id)
        ->call('saveDish')
        ->assertHasNoErrors();

    $dish = Dish::query()->sole();

    expect($dish->name)->toBe('Baked Chicken')
        ->and($dish->normalized_name)->toBe('baked chicken')
        ->and($dish->note)->toBe('Family favorite')
        ->and($dish->ingredients()->count())->toBe(2)
        ->and($dish->mainProtein()->sole()->is($mainProtein))->toBeTrue()
        ->and($dish->proteinCategory())->toBe(ProteinCategory::Poultry);
});

test('choosing a main protein automatically selects it as a dish ingredient', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$mainProtein] = ingredientsForDish($family, ProteinCategory::Fish);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->assertSeeInOrder([
            'Choose the main protein first.',
            'Select any additional ingredients.',
        ])
        ->set('name', 'Salmon Dinner')
        ->set('mainProteinIngredientId', $mainProtein->id)
        ->assertSet('ingredientIds', [$mainProtein->id])
        ->call('saveDish')
        ->assertHasNoErrors();

    $dish = Dish::query()->sole();

    expect($dish->ingredients()->sole()->is($mainProtein))->toBeTrue()
        ->and($dish->mainProtein()->sole()->is($mainProtein))->toBeTrue();
});

test('removing the main protein ingredient clears its designation', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$mainProtein] = ingredientsForDish($family);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->set('mainProteinIngredientId', $mainProtein->id)
        ->set('ingredientIds', [])
        ->assertSet('mainProteinIngredientId', null);
});

test('a dish requires ingredients and exactly one main protein', function () {
    $head = User::factory()->create();
    Family::factory()->for($head, 'head')->create();

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->set('name', 'Invalid Dish')
        ->call('saveDish')
        ->assertHasErrors(['ingredient_ids', 'main_protein_ingredient_id']);

    expect(Dish::query()->exists())->toBeFalse();
});

test('a dish cannot designate multiple main proteins', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$firstProtein] = ingredientsForDish($family, ProteinCategory::Beef);
    $secondProtein = Ingredient::factory()->for($family)->create([
        'protein_category' => ProteinCategory::Pork,
    ]);

    expect(fn () => app(CreateDish::class)->execute($head, $family, [
        'name' => 'Two Proteins',
        'ingredient_ids' => [$firstProtein->id, $secondProtein->id],
        'main_protein_ingredient_id' => [$firstProtein->id, $secondProtein->id],
    ]))->toThrow(ValidationException::class);

    expect(Dish::query()->exists())->toBeFalse();
});

test('the main protein must be selected and categorized', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$categorized, $uncategorized] = ingredientsForDish($family);

    expect(fn () => app(CreateDish::class)->execute($head, $family, [
        'name' => 'Missing Selection',
        'ingredient_ids' => [$uncategorized->id],
        'main_protein_ingredient_id' => $categorized->id,
    ]))->toThrow(ValidationException::class);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->set('name', 'Missing Category')
        ->set('ingredientIds', [$uncategorized->id])
        ->set('mainProteinIngredientId', $uncategorized->id)
        ->call('saveDish')
        ->assertHasErrors(['main_protein_ingredient_id']);

    expect(Dish::query()->exists())->toBeFalse();
});

test('dish ingredients must belong to the same family', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $privateIngredient = Ingredient::factory()->create([
        'protein_category' => ProteinCategory::Fish,
    ]);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->set('name', 'Private Ingredient Dish')
        ->set('ingredientIds', [$privateIngredient->id])
        ->set('mainProteinIngredientId', $privateIngredient->id)
        ->call('saveDish')
        ->assertHasErrors(['ingredient_ids']);

    expect(Dish::query()->exists())->toBeFalse();
});

test('active duplicate names are rejected regardless of capitalization', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$mainProtein] = ingredientsForDish($family);
    $existingDish = Dish::factory()->for($family)->create([
        'name' => 'Meatloaf',
        'normalized_name' => 'meatloaf',
    ]);
    attachValidDishIngredients($existingDish, $mainProtein);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->set('name', '  MEATLOAF ')
        ->set('ingredientIds', [$mainProtein->id])
        ->set('mainProteinIngredientId', $mainProtein->id)
        ->call('saveDish')
        ->assertHasErrors(['name']);

    expect(Dish::query()->count())->toBe(1);
});

test('an archived duplicate prompts the member to restore the existing dish', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$mainProtein] = ingredientsForDish($family);
    $archivedDish = Dish::factory()->for($family)->archived()->create([
        'name' => 'Meatloaf',
        'normalized_name' => 'meatloaf',
        'note' => 'Original version',
    ]);
    attachValidDishIngredients($archivedDish, $mainProtein);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->set('name', 'MEATLOAF')
        ->set('note', 'Replacement version')
        ->set('ingredientIds', [$mainProtein->id])
        ->set('mainProteinIngredientId', $mainProtein->id)
        ->call('saveDish')
        ->assertSet('archivedConflictDishId', $archivedDish->id)
        ->assertHasErrors(['archived_dish'])
        ->call('restoreConflict')
        ->assertHasNoErrors();

    expect(Dish::query()->count())->toBe(1)
        ->and($archivedDish->refresh()->archived_at)->toBeNull()
        ->and($archivedDish->note)->toBe('Original version');
});

test('an archived duplicate can be replaced and restored', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$oldProtein] = ingredientsForDish($family, ProteinCategory::Beef);
    $newProtein = Ingredient::factory()->for($family)->create([
        'name' => 'Tofu',
        'normalized_name' => 'tofu',
        'protein_category' => ProteinCategory::Vegetable,
    ]);
    $archivedDish = Dish::factory()->for($family)->archived()->create([
        'name' => 'Dinner Bowl',
        'normalized_name' => 'dinner bowl',
    ]);
    attachValidDishIngredients($archivedDish, $oldProtein);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->set('name', 'Dinner Bowl')
        ->set('note', 'New version')
        ->set('ingredientIds', [$newProtein->id])
        ->set('mainProteinIngredientId', $newProtein->id)
        ->call('saveDish')
        ->call('replaceConflict')
        ->assertHasNoErrors();

    expect(Dish::query()->count())->toBe(1)
        ->and($archivedDish->refresh()->archived_at)->toBeNull()
        ->and($archivedDish->note)->toBe('New version')
        ->and($archivedDish->mainProtein()->sole()->is($newProtein))->toBeTrue()
        ->and($archivedDish->proteinCategory())->toBe(ProteinCategory::Vegetable);
});

test('members can edit dish details and ingredient selections', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$oldProtein, $otherIngredient] = ingredientsForDish($family);
    $newProtein = Ingredient::factory()->for($family)->create([
        'protein_category' => ProteinCategory::Fish,
    ]);
    $dish = Dish::factory()->for($family)->create();
    attachValidDishIngredients($dish, $oldProtein, $otherIngredient);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->call('startEditing', $dish->id)
        ->set('name', 'Updated Fish Dinner')
        ->set('note', 'Updated note')
        ->set('ingredientIds', [$newProtein->id, $otherIngredient->id])
        ->set('mainProteinIngredientId', $newProtein->id)
        ->call('saveDish')
        ->assertHasNoErrors();

    expect($dish->refresh()->name)->toBe('Updated Fish Dinner')
        ->and($dish->note)->toBe('Updated note')
        ->and($dish->ingredients()->count())->toBe(2)
        ->and($dish->mainProtein()->sole()->is($newProtein))->toBeTrue();
});

test('archiving hides a dish from active selection without deleting it', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$mainProtein] = ingredientsForDish($family);
    $dish = Dish::factory()->for($family)->create(['name' => 'Visible Dinner']);
    attachValidDishIngredients($dish, $mainProtein);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->assertSee('Visible Dinner')
        ->call('archiveDish', $dish->id)
        ->assertDontSee('Visible Dinner');

    expect($dish->fresh())->not->toBeNull()
        ->and($dish->refresh()->isArchived())->toBeTrue()
        ->and($dish->ingredients()->count())->toBe(1)
        ->and($family->dishes()->active()->whereKey($dish->id)->exists())->toBeFalse();
});

test('restoring makes an archived dish active and eligible again', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$mainProtein] = ingredientsForDish($family);
    $dish = Dish::factory()->for($family)->archived()->create(['name' => 'Archived Dinner']);
    attachValidDishIngredients($dish, $mainProtein);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->set('archiveFilter', 'archived')
        ->assertSee('Archived Dinner')
        ->call('restoreDish', $dish->id);

    expect($dish->refresh()->isArchived())->toBeFalse()
        ->and($family->dishes()->active()->whereKey($dish->id)->exists())->toBeTrue();
});

test('search and category and archive filters remain family scoped', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$beef, $onion] = ingredientsForDish($family, ProteinCategory::Beef);
    $onion->update(['name' => 'Onion', 'normalized_name' => 'onion']);
    $fish = Ingredient::factory()->for($family)->create([
        'name' => 'Salmon',
        'normalized_name' => 'salmon',
        'protein_category' => ProteinCategory::Fish,
    ]);

    $beefDish = Dish::factory()->for($family)->create(['name' => 'Beef Supper']);
    $fishDish = Dish::factory()->for($family)->create(['name' => 'Ocean Dinner']);
    $archivedDish = Dish::factory()->for($family)->archived()->create(['name' => 'Archived Beef']);
    attachValidDishIngredients($beefDish, $beef, $onion);
    attachValidDishIngredients($fishDish, $fish);
    attachValidDishIngredients($archivedDish, $beef);

    $privateDish = Dish::factory()->create(['name' => 'Private Onion Dinner']);

    Livewire::actingAs($head)
        ->test('pages::dishes')
        ->set('search', 'onion')
        ->assertSee('Beef Supper')
        ->assertDontSee('Ocean Dinner')
        ->assertDontSee('Private Onion Dinner')
        ->set('search', '')
        ->set('categoryFilter', ProteinCategory::Fish->value)
        ->assertSee('Ocean Dinner')
        ->assertDontSee('Beef Supper')
        ->set('categoryFilter', '')
        ->set('archiveFilter', 'archived')
        ->assertSee('Archived Beef')
        ->assertDontSee('Beef Supper');

    expect($privateDish->fresh())->not->toBeNull();
});

test('dish records and actions are isolated from other families', function () {
    $user = User::factory()->create();
    $family = Family::factory()->for($user, 'head')->create();
    [$mainProtein] = ingredientsForDish($family);
    $privateDish = Dish::factory()->create(['name' => 'Private Dish']);

    $component = Livewire::actingAs($user)
        ->test('pages::dishes')
        ->assertDontSee('Private Dish');

    expect(fn () => $component->call('archiveDish', $privateDish->id))
        ->toThrow(ModelNotFoundException::class);

    expect($privateDish->refresh()->archived_at)->toBeNull()
        ->and($family->ingredients()->whereKey($mainProtein->id)->exists())->toBeTrue();
});
