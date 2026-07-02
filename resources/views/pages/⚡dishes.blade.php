<?php

use App\Actions\Dishes\ArchiveDish;
use App\Actions\Dishes\CreateDish;
use App\Actions\Dishes\NormalizeDishName;
use App\Actions\Dishes\ReplaceArchivedDish;
use App\Actions\Dishes\RestoreDish;
use App\Actions\Dishes\UpdateDish;
use App\Actions\Families\ResolveActiveFamily;
use App\Models\Dish;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\ProteinCategory;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dishes')] class extends Component {
    public string $search = '';

    public string $categoryFilter = '';

    public string $archiveFilter = 'active';

    public string $name = '';

    public string $note = '';

    /** @var list<int|string> */
    public array $ingredientIds = [];

    public ?int $mainProteinIngredientId = null;

    #[Locked]
    public ?int $activeFamilyId = null;

    #[Locked]
    public ?int $editingDishId = null;

    #[Locked]
    public ?int $archivedConflictDishId = null;

    private ?Family $resolvedActiveFamily = null;

    public function mount(ResolveActiveFamily $resolveActiveFamily): void
    {
        $family = $resolveActiveFamily->execute($this->user());

        if (is_null($family)) {
            $this->redirectRoute('families.index', navigate: true);

            return;
        }

        Gate::authorize('view', $family);

        $this->activeFamilyId = $family->id;
    }

    public function updatedMainProteinIngredientId(mixed $ingredientId): void
    {
        if (! is_numeric($ingredientId)) {
            return;
        }

        $ingredientId = (int) $ingredientId;
        $selectedIngredientIds = $this->integerIngredientIds();

        if (! in_array($ingredientId, $selectedIngredientIds, true)) {
            $this->ingredientIds[] = $ingredientId;
        }

        $this->resetValidation(['ingredient_ids', 'main_protein_ingredient_id']);
    }

    public function updatedIngredientIds(): void
    {
        if ($this->mainProteinIngredientId
            && ! in_array($this->mainProteinIngredientId, $this->integerIngredientIds(), true)) {
            $this->mainProteinIngredientId = null;
        }

        $this->resetValidation(['ingredient_ids', 'main_protein_ingredient_id']);
    }

    public function startCreating(): void
    {
        Gate::authorize('create', [Dish::class, $this->activeFamily()]);

        $this->resetForm();
        Flux::modal('dish-form')->show();
    }

    public function startEditing(int $dishId): void
    {
        $dish = $this->dish($dishId);

        Gate::authorize('update', $dish);

        $dish->load('ingredients');

        $this->resetForm();
        $this->editingDishId = $dish->id;
        $this->name = $dish->name;
        $this->note = $dish->note ?? '';
        $this->ingredientIds = $dish->ingredients->modelKeys();
        $this->mainProteinIngredientId = $dish->mainProtein()->first()?->id;

        Flux::modal('dish-form')->show();
    }

    public function saveDish(
        CreateDish $createDish,
        UpdateDish $updateDish,
        NormalizeDishName $normalizeDishName,
    ): void {
        $this->resetValidation();
        $this->archivedConflictDishId = null;

        try {
            if ($this->editingDishId) {
                $updateDish->execute($this->user(), $this->dish($this->editingDishId), $this->formAttributes());
            } else {
                $createDish->execute($this->user(), $this->activeFamily(), $this->formAttributes());
            }
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['archived_dish'])) {
                throw $exception;
            }

            $normalizedName = $normalizeDishName->execute($this->name)['normalized_name'];
            $conflict = $this->activeFamily()->dishes()
                ->archived()
                ->where('normalized_name', $normalizedName)
                ->first();

            $this->archivedConflictDishId = $conflict?->id;
            $this->addError('archived_dish', $exception->errors()['archived_dish'][0]);

            return;
        }

        $message = $this->editingDishId ? __('Dish updated.') : __('Dish created.');

        $this->resetForm();
        unset($this->dishes);

        Flux::modal('dish-form')->close();
        Flux::toast(variant: 'success', text: $message);
    }

    public function restoreConflict(RestoreDish $restoreDish): void
    {
        $dish = $this->dish($this->archivedConflictDishId);

        $restoreDish->execute($this->user(), $dish);
        $this->resetForm();
        unset($this->dishes);

        Flux::modal('dish-form')->close();
        Flux::toast(variant: 'success', text: __('Archived dish restored.'));
    }

    public function replaceConflict(ReplaceArchivedDish $replaceArchivedDish): void
    {
        $dish = $this->dish($this->archivedConflictDishId);

        $replaceArchivedDish->execute($this->user(), $dish, $this->formAttributes());
        $this->resetForm();
        unset($this->dishes);

        Flux::modal('dish-form')->close();
        Flux::toast(variant: 'success', text: __('Archived dish replaced and restored.'));
    }

    public function archiveDish(int $dishId, ArchiveDish $archiveDish): void
    {
        $archiveDish->execute($this->user(), $this->dish($dishId));
        unset($this->dishes);

        Flux::toast(variant: 'success', text: __('Dish archived.'));
    }

    public function restoreDish(int $dishId, RestoreDish $restoreDish): void
    {
        $restoreDish->execute($this->user(), $this->dish($dishId));
        unset($this->dishes);

        Flux::toast(variant: 'success', text: __('Dish restored.'));
    }

    public function resetForm(): void
    {
        $this->reset(
            'name',
            'note',
            'ingredientIds',
            'mainProteinIngredientId',
            'editingDishId',
            'archivedConflictDishId',
        );
        $this->resetValidation();
    }

    /** @return Collection<int, Dish> */
    #[Computed]
    public function dishes(): Collection
    {
        $query = $this->activeFamily()->dishes()
            ->with(['ingredients' => fn ($query) => $query->orderBy('name'), 'mainProtein']);

        $query->when($this->search !== '', function (Builder $query): void {
            $search = '%'.trim($this->search).'%';

            $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', $search)
                    ->orWhereHas('ingredients', fn (Builder $query) => $query->where('name', 'like', $search));
            });
        });

        $query->when($this->categoryFilter !== '', function (Builder $query): void {
            $query->whereHas('mainProtein', fn (Builder $query) => $query->where('protein_category', $this->categoryFilter));
        });

        match ($this->archiveFilter) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        return $query->orderBy('name')->get();
    }

    /** @return Collection<int, Ingredient> */
    #[Computed]
    public function ingredients(): Collection
    {
        return $this->activeFamily()->ingredients()->orderBy('name')->get();
    }

    /**
     * @return array{name: string, note: string|null, ingredient_ids: list<int|string>, main_protein_ingredient_id: int|null}
     */
    private function formAttributes(): array
    {
        return [
            'name' => $this->name,
            'note' => $this->note === '' ? null : $this->note,
            'ingredient_ids' => $this->ingredientIds,
            'main_protein_ingredient_id' => $this->mainProteinIngredientId,
        ];
    }

    /** @return list<int> */
    private function integerIngredientIds(): array
    {
        return collect($this->ingredientIds)
            ->filter(fn (mixed $ingredientId): bool => is_numeric($ingredientId))
            ->map(fn (mixed $ingredientId): int => (int) $ingredientId)
            ->unique()
            ->values()
            ->all();
    }

    private function dish(?int $dishId): Dish
    {
        return $this->activeFamily()->dishes()->findOrFail($dishId);
    }

    private function activeFamily(): Family
    {
        return $this->resolvedActiveFamily ??= $this->user()->families()->findOrFail($this->activeFamilyId);
    }

    private function user(): User
    {
        return Auth::user();
    }
};
?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-8">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Dishes') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Build and maintain the dinner collection for :family.', ['family' => $this->activeFamily()->name]) }}</flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('dishes.import')" wire:navigate>
                {{ __('Import notes') }}
            </flux:button>

            <flux:button variant="primary" icon="plus" wire:click="startCreating" wire:loading.attr="disabled">
                {{ __('Add dish') }}
            </flux:button>
        </div>
    </div>

    <flux:card class="grid gap-4 md:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :label="__('Search')" placeholder="{{ __('Dish or ingredient') }}" />

        <flux:select wire:model.live="categoryFilter" :label="__('Category')">
            <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
            @foreach (ProteinCategory::cases() as $category)
                <flux:select.option wire:key="filter-category-{{ $category->value }}" :value="$category->value">
                    {{ $category->label() }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="archiveFilter" :label="__('Status')">
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="archived">{{ __('Archived') }}</flux:select.option>
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        </flux:select>
    </flux:card>

    <section class="grid gap-4 lg:grid-cols-2">
        @forelse ($this->dishes as $dish)
            <flux:card wire:key="dish-{{ $dish->id }}" class="flex flex-col gap-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <flux:heading class="truncate">{{ $dish->name }}</flux:heading>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @if ($dish->proteinCategory())
                                <flux:badge>{{ $dish->proteinCategory()->label() }}</flux:badge>
                            @endif
                            @if ($dish->isArchived())
                                <flux:badge color="zinc">{{ __('Archived') }}</flux:badge>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($dish->note)
                    <flux:text>{{ $dish->note }}</flux:text>
                @endif

                <div>
                    <flux:text class="font-medium">{{ __('Ingredients') }}</flux:text>
                    <flux:text>{{ $dish->ingredients->pluck('name')->join(', ') }}</flux:text>
                </div>

                <div class="mt-auto flex flex-wrap gap-2">
                    <flux:button size="sm" wire:click="startEditing({{ $dish->id }})" wire:loading.attr="disabled">
                        {{ __('Edit') }}
                    </flux:button>

                    @if ($dish->isArchived())
                        <flux:button size="sm" variant="primary" wire:click="restoreDish({{ $dish->id }})" wire:loading.attr="disabled">
                            {{ __('Restore') }}
                        </flux:button>
                    @else
                        <flux:button
                            size="sm"
                            variant="danger"
                            wire:click="archiveDish({{ $dish->id }})"
                            wire:confirm="{{ __('Archive :name?', ['name' => $dish->name]) }}"
                            wire:loading.attr="disabled"
                        >
                            {{ __('Archive') }}
                        </flux:button>
                    @endif
                </div>
            </flux:card>
        @empty
            <flux:callout class="lg:col-span-2" icon="book-open" heading="{{ __('No dishes found') }}">
                {{ $this->ingredients->isEmpty()
                    ? __('Add ingredients first, then create your family’s first dish.')
                    : __('Create a dish or adjust the current search and filters.') }}
            </flux:callout>
        @endforelse
    </section>

    <flux:modal name="dish-form" class="max-w-2xl" @close="resetForm">
        <form wire:submit="saveDish" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingDishId ? __('Edit dish') : __('Add dish') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Choose the main protein first. It will automatically be included in the ingredient list.') }}</flux:text>
            </div>

            @if ($archivedConflictDishId)
                <flux:callout variant="warning" icon="archive-box" heading="{{ __('Archived dish found') }}">
                    <div class="flex flex-col gap-4">
                        <span>{{ $errors->first('archived_dish') }}</span>
                        <div class="flex flex-wrap gap-2">
                            <flux:button type="button" size="sm" wire:click="restoreConflict" wire:loading.attr="disabled">
                                {{ __('Restore existing') }}
                            </flux:button>
                            <flux:button type="button" size="sm" variant="primary" wire:click="replaceConflict" wire:loading.attr="disabled">
                                {{ __('Replace with this version') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:callout>
            @endif

            <flux:input wire:model="name" :label="__('Dish name')" required />
            <flux:textarea wire:model="note" :label="__('Optional note')" rows="3" />

            <flux:select wire:model.live.number="mainProteinIngredientId" :label="__('Main protein')" required>
                <flux:select.option value="">{{ __('Choose a main protein') }}</flux:select.option>
                @foreach ($this->ingredients->whereNotNull('protein_category') as $ingredient)
                    <flux:select.option wire:key="main-protein-{{ $ingredient->id }}" :value="$ingredient->id">
                        {{ $ingredient->name }} — {{ $ingredient->protein_category->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="main_protein_ingredient_id" />

            <div class="space-y-3">
                <div>
                    <flux:heading size="sm">{{ __('Ingredients') }}</flux:heading>
                    <flux:text>{{ __('Select any additional ingredients.') }}</flux:text>
                </div>

                @forelse ($this->ingredients as $ingredient)
                    <label wire:key="dish-ingredient-{{ $ingredient->id }}" class="grid cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <span class="flex items-center justify-center">
                            <flux:checkbox wire:model.live="ingredientIds" :value="$ingredient->id" />
                        </span>

                        <span class="min-w-0 truncate text-sm font-medium leading-5">{{ $ingredient->name }}</span>

                        @if ($ingredient->protein_category)
                            <flux:badge>{{ $ingredient->protein_category->label() }}</flux:badge>
                        @else
                            <span aria-hidden="true"></span>
                        @endif
                    </label>
                @empty
                    <flux:callout variant="warning" icon="list-bullet" heading="{{ __('Ingredients required') }}">
                        <flux:link :href="route('ingredients.index')" wire:navigate>{{ __('Add ingredients before creating a dish.') }}</flux:link>
                    </flux:callout>
                @endforelse

                <flux:error name="ingredient_ids" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" :disabled="$this->ingredients->isEmpty()">
                    {{ $editingDishId ? __('Save changes') : __('Create dish') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
