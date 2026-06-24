<?php

use App\Actions\Families\ResolveActiveFamily;
use App\Actions\Ingredients\CreateIngredient;
use App\Actions\Ingredients\DeleteIngredient;
use App\Actions\Ingredients\UpdateIngredient;
use App\Models\Family;
use App\Models\Ingredient;
use App\Models\User;
use App\ProteinCategory;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ingredients')] class extends Component {
    public string $name = '';

    public string $proteinCategory = '';

    #[Locked]
    public ?int $activeFamilyId = null;

    #[Locked]
    public ?int $editingIngredientId = null;

    #[Locked]
    public int $affectedDishCount = 0;

    public string $editName = '';

    public string $editProteinCategory = '';

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

    public function createIngredient(CreateIngredient $createIngredient): void
    {
        $this->resetValidation(['name', 'protein_category']);

        $createIngredient->execute($this->user(), $this->activeFamily(), [
            'name' => $this->name,
            'protein_category' => $this->proteinCategory ?: null,
        ]);

        $this->reset('name', 'proteinCategory');
        $this->resetValidation(['name', 'protein_category']);
        unset($this->ingredients);

        Flux::toast(variant: 'success', text: __('Ingredient added.'));
    }

    public function startEditing(int $ingredientId): void
    {
        $ingredient = $this->ingredient($ingredientId);

        Gate::authorize('update', $ingredient);

        $this->editingIngredientId = $ingredient->id;
        $this->editName = $ingredient->name;
        $this->editProteinCategory = $ingredient->protein_category?->value ?? '';
        $this->affectedDishCount = $ingredient->dishes()->count();
        $this->resetValidation();

        Flux::modal('edit-ingredient')->show();
    }

    public function updateIngredient(UpdateIngredient $updateIngredient): void
    {
        $ingredient = $this->ingredient($this->editingIngredientId);

        $updateIngredient->execute($this->user(), $ingredient, [
            'name' => $this->editName,
            'protein_category' => $this->editProteinCategory ?: null,
        ]);

        $this->resetEditing();
        unset($this->ingredients);

        Flux::modal('edit-ingredient')->close();
        Flux::toast(variant: 'success', text: __('Ingredient updated.'));
    }

    public function deleteIngredient(int $ingredientId, DeleteIngredient $deleteIngredient): void
    {
        $deleteIngredient->execute($this->user(), $this->ingredient($ingredientId));
        unset($this->ingredients);

        Flux::toast(variant: 'success', text: __('Ingredient deleted.'));
    }

    public function resetEditing(): void
    {
        $this->reset('editingIngredientId', 'editName', 'editProteinCategory', 'affectedDishCount');
        $this->resetValidation();
    }

    /** @return Collection<int, Ingredient> */
    #[Computed]
    public function ingredients(): Collection
    {
        return $this->activeFamily()->ingredients()
            ->withCount('dishes')
            ->orderBy('name')
            ->get();
    }

    private function ingredient(?int $ingredientId): Ingredient
    {
        return $this->activeFamily()->ingredients()->findOrFail($ingredientId);
    }

    private function activeFamily(): Family
    {
        return $this->user()->families()->findOrFail($this->activeFamilyId);
    }

    private function user(): User
    {
        return Auth::user();
    }
};
?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-8">
    <div>
        <flux:heading size="xl">{{ __('Ingredients') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Build the reusable ingredient collection for :family.', ['family' => $this->activeFamily()->name]) }}</flux:text>
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
        <section class="order-2 flex flex-col gap-4 lg:order-1">
            <div>
                <flux:heading>{{ __('Family ingredients') }}</flux:heading>
                <flux:text>{{ __('Editing an ingredient updates every dish that uses it.') }}</flux:text>
            </div>

            <flux:error name="ingredient" />

            @forelse ($this->ingredients as $ingredient)
                <flux:card wire:key="ingredient-{{ $ingredient->id }}" class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading class="truncate">{{ $ingredient->name }}</flux:heading>

                            @if ($ingredient->protein_category)
                                <flux:badge>{{ $ingredient->protein_category->label() }}</flux:badge>
                            @endif
                        </div>
                        <flux:text>{{ trans_choice(':count connected dish|:count connected dishes', $ingredient->dishes_count, ['count' => $ingredient->dishes_count]) }}</flux:text>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" wire:click="startEditing({{ $ingredient->id }})" wire:loading.attr="disabled">
                            {{ __('Edit') }}
                        </flux:button>
                        <flux:button
                            size="sm"
                            variant="danger"
                            wire:click="deleteIngredient({{ $ingredient->id }})"
                            wire:confirm="{{ __('Delete :name?', ['name' => $ingredient->name]) }}"
                            wire:loading.attr="disabled"
                            :disabled="$ingredient->dishes_count > 0"
                        >
                            {{ __('Delete') }}
                        </flux:button>
                    </div>
                </flux:card>
            @empty
                <flux:callout icon="list-bullet" heading="{{ __('No ingredients yet') }}">
                    {{ __('Add the first ingredient your family uses for dinner.') }}
                </flux:callout>
            @endforelse
        </section>

        <aside class="order-1 lg:order-2">
            <flux:card>
                <form wire:submit="createIngredient" class="flex flex-col gap-5">
                    <div>
                        <flux:heading>{{ __('Add ingredient') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Protein category is optional.') }}</flux:text>
                    </div>

                    <flux:input wire:model="name" :label="__('Ingredient name')" required />

                    <flux:select wire:model="proteinCategory" :label="__('Protein category')">
                        <flux:select.option value="">{{ __('None') }}</flux:select.option>
                        @foreach (ProteinCategory::cases() as $category)
                            <flux:select.option wire:key="new-category-{{ $category->value }}" :value="$category->value">
                                {{ $category->label() }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="protein_category" />

                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled">
                        {{ __('Add ingredient') }}
                    </flux:button>
                </form>
            </flux:card>
        </aside>
    </div>

    <flux:modal name="edit-ingredient" class="max-w-lg" @close="resetEditing">
        <form wire:submit="updateIngredient" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit ingredient') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ trans_choice('This change will affect :count connected dish.|This change will affect :count connected dishes.', $affectedDishCount, ['count' => $affectedDishCount]) }}
                </flux:text>
            </div>

            <flux:input wire:model="editName" :label="__('Ingredient name')" required />
            <flux:error name="name" />

            <flux:select wire:model="editProteinCategory" :label="__('Protein category')">
                <flux:select.option value="">{{ __('None') }}</flux:select.option>
                @foreach (ProteinCategory::cases() as $category)
                    <flux:select.option wire:key="edit-category-{{ $category->value }}" :value="$category->value">
                        {{ $category->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="protein_category" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled">
                    {{ __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
