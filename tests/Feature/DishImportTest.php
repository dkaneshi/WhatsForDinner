<?php

use App\Actions\Dishes\CreateDish;
use App\Actions\DishImports\ConfirmDishImport;
use App\Actions\DishImports\ParseMarkdownDishImport;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\ProteinCategory;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('approved sample format parses correctly', function () {
    $preview = app(ParseMarkdownDishImport::class)->execute(<<<'MARKDOWN'
## Meatloaf
Family favorite.

* Hamburger (beef)
* Eggs
* Breadcrumbs
* Lipton beefy onion soup mix

## Shoyu Chicken
* Chicken (poultry)
* Shoyu
* Ginger
MARKDOWN);

    expect($preview)->toHaveCount(2)
        ->and($preview[0]['name'])->toBe('Meatloaf')
        ->and($preview[0]['note'])->toBe('Family favorite.')
        ->and($preview[0]['ingredients'][0])->toMatchArray([
            'name' => 'Hamburger',
            'protein_category' => ProteinCategory::Beef->value,
            'is_main_protein' => true,
        ])
        ->and($preview[0]['ingredients'][1])->toMatchArray([
            'name' => 'Eggs',
            'protein_category' => null,
            'is_main_protein' => false,
        ])
        ->and($preview[1]['name'])->toBe('Shoyu Chicken');
});

test('each supported protein category parses correctly', function (ProteinCategory $category) {
    $preview = app(ParseMarkdownDishImport::class)->execute(<<<MARKDOWN
## {$category->label()} Dinner
* Main Ingredient ({$category->value})
MARKDOWN);

    expect($preview[0]['ingredients'][0]['protein_category'])->toBe($category->value)
        ->and($preview[0]['ingredients'][0]['is_main_protein'])->toBeTrue();
})->with(ProteinCategory::cases());

test('missing and multiple protein suffixes are flagged', function () {
    $head = User::factory()->create();
    Family::factory()->for($head, 'head')->create();

    Livewire::actingAs($head)
        ->test('pages::dish-import')
        ->set('markdown', <<<'MARKDOWN'
## Missing Protein
* Eggs
* Breadcrumbs

## Multiple Proteins
* Hamburger (beef)
* Chicken (poultry)
MARKDOWN)
        ->call('previewImport')
        ->assertSet('previewIsValid', false)
        ->assertSee('Choose exactly one ingredient as the main protein.');
});

test('duplicate dish and ingredient conflicts require resolution', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    Dish::factory()->for($family)->create([
        'name' => 'Meatloaf',
        'normalized_name' => 'meatloaf',
    ]);
    Ingredient::factory()->for($family)->create([
        'name' => 'Hamburger',
        'normalized_name' => 'hamburger',
        'protein_category' => ProteinCategory::Poultry,
    ]);

    Livewire::actingAs($head)
        ->test('pages::dish-import')
        ->set('markdown', <<<'MARKDOWN'
## Meatloaf
* Hamburger (beef)
* Eggs
MARKDOWN)
        ->call('previewImport')
        ->assertSet('previewIsValid', false)
        ->assertSee('A dish named Meatloaf already exists in this family.')
        ->assertSee('The existing ingredient Hamburger is categorized as Poultry, not Beef.')
        ->set('previewRows.0.name', 'Meatloaf Import')
        ->set('previewRows.0.ingredients.0.protein_category', ProteinCategory::Poultry->value)
        ->assertSet('previewIsValid', true);
});

test('likely ingredient duplicates are suggested without automatic merging', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    Ingredient::factory()->for($family)->create([
        'name' => 'Tomatoes',
        'normalized_name' => 'tomatoes',
    ]);

    Livewire::actingAs($head)
        ->test('pages::dish-import')
        ->set('markdown', <<<'MARKDOWN'
## Canned Salmon
* Canned Salmon (fish)
* Tomatos
MARKDOWN)
        ->call('previewImport')
        ->assertSet('previewIsValid', true)
        ->assertSee('Tomatos may already exist as Tomatoes.');

    expect($family->ingredients()->where('normalized_name', 'tomatos')->exists())->toBeFalse();
});

test('canceling preview writes nothing', function () {
    $head = User::factory()->create();
    Family::factory()->for($head, 'head')->create();

    Livewire::actingAs($head)
        ->test('pages::dish-import')
        ->set('markdown', <<<'MARKDOWN'
## Meatloaf
* Hamburger (beef)
* Eggs
MARKDOWN)
        ->call('previewImport')
        ->assertSet('hasPreview', true)
        ->call('cancelPreview')
        ->assertSet('hasPreview', false);

    expect(Dish::query()->exists())->toBeFalse()
        ->and(Ingredient::query()->exists())->toBeFalse();
});

test('any failed record rolls back the full import', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $previewRows = app(ParseMarkdownDishImport::class)->execute(<<<'MARKDOWN'
## First Dish
* Hamburger (beef)

## Second Dish
* Chicken (poultry)
MARKDOWN);
    $createDish = Mockery::mock(CreateDish::class);
    $createDish->shouldReceive('execute')
        ->once()
        ->andReturnUsing(fn () => Dish::factory()->for($family)->create([
            'name' => 'First Dish',
            'normalized_name' => 'first dish',
        ]));
    $createDish->shouldReceive('execute')
        ->once()
        ->andThrow(ValidationException::withMessages(['name' => 'Forced failure']));

    app()->instance(CreateDish::class, $createDish);

    expect(fn () => app(ConfirmDishImport::class)->execute($head, $family, $previewRows))
        ->toThrow(ValidationException::class);

    expect(Dish::query()->exists())->toBeFalse()
        ->and(Ingredient::query()->exists())->toBeFalse();
});

test('valid confirmed import creates expected dishes ingredients and relationships', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    Ingredient::factory()->for($family)->create([
        'name' => 'Eggs',
        'normalized_name' => 'eggs',
    ]);

    Livewire::actingAs($head)
        ->test('pages::dish-import')
        ->set('markdown', <<<'MARKDOWN'
## Meatloaf
Family favorite.
* Hamburger (beef)
* Eggs
* Breadcrumbs

## Tuna Tofu
* Canned Tuna (fish)
* Tofu
* Shoyu
MARKDOWN)
        ->call('previewImport')
        ->assertSet('previewIsValid', true)
        ->call('confirmImport')
        ->assertHasNoErrors();

    expect($family->dishes()->count())->toBe(2)
        ->and($family->ingredients()->count())->toBe(6);

    $meatloaf = $family->dishes()->where('normalized_name', 'meatloaf')->firstOrFail();

    expect($meatloaf->note)->toBe('Family favorite.')
        ->and($meatloaf->ingredients()->pluck('normalized_name')->sort()->values()->all())
        ->toBe(['breadcrumbs', 'eggs', 'hamburger'])
        ->and($meatloaf->mainProtein()->sole()->normalized_name)->toBe('hamburger')
        ->and($meatloaf->proteinCategory())->toBe(ProteinCategory::Beef);
});

test('users without a family are redirected to family setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dishes.import'))
        ->assertRedirect(route('families.index'));
});
