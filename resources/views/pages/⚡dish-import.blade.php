<?php

use App\Actions\DishImports\ConfirmDishImport;
use App\Actions\DishImports\ParseMarkdownDishImport;
use App\Actions\DishImports\ValidateDishImportPreview;
use App\Actions\Families\ResolveActiveFamily;
use App\Models\Family;
use App\Models\User;
use App\ProteinCategory;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Import dishes')] class extends Component {
    public string $markdown = '';

    /** @var list<array<string, mixed>> */
    public array $previewRows = [];

    public bool $hasPreview = false;

    public bool $previewIsValid = false;

    #[Locked]
    public ?int $activeFamilyId = null;

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

    public function previewImport(
        ParseMarkdownDishImport $parseMarkdownDishImport,
        ValidateDishImportPreview $validateDishImportPreview,
    ): void {
        $this->resetValidation();

        $this->validate([
            'markdown' => ['required', 'string'],
        ]);

        $this->previewRows = $parseMarkdownDishImport->execute($this->markdown);
        $this->hasPreview = true;

        $this->validatePreview($validateDishImportPreview);
    }

    public function updatedPreviewRows(ValidateDishImportPreview $validateDishImportPreview): void
    {
        if (! $this->hasPreview) {
            return;
        }

        $this->validatePreview($validateDishImportPreview);
    }

    public function markMainProtein(int $dishIndex, int $ingredientIndex, ValidateDishImportPreview $validateDishImportPreview): void
    {
        foreach ($this->previewRows[$dishIndex]['ingredients'] ?? [] as $index => $ingredient) {
            $this->previewRows[$dishIndex]['ingredients'][$index]['is_main_protein'] = $index === $ingredientIndex;
        }

        $this->validatePreview($validateDishImportPreview);
    }

    public function addIngredient(int $dishIndex, ValidateDishImportPreview $validateDishImportPreview): void
    {
        $this->previewRows[$dishIndex]['ingredients'][] = [
            'name' => '',
            'protein_category' => null,
            'is_main_protein' => false,
        ];

        $this->validatePreview($validateDishImportPreview);
    }

    public function removeIngredient(int $dishIndex, int $ingredientIndex, ValidateDishImportPreview $validateDishImportPreview): void
    {
        unset($this->previewRows[$dishIndex]['ingredients'][$ingredientIndex]);
        $this->previewRows[$dishIndex]['ingredients'] = array_values($this->previewRows[$dishIndex]['ingredients']);

        $this->validatePreview($validateDishImportPreview);
    }

    public function cancelPreview(): void
    {
        $this->reset('previewRows', 'hasPreview', 'previewIsValid');
        $this->resetValidation();
    }

    public function confirmImport(ConfirmDishImport $confirmDishImport, ValidateDishImportPreview $validateDishImportPreview): void
    {
        $this->validatePreview($validateDishImportPreview);

        if (! $this->previewIsValid) {
            $this->addError('preview', __('Resolve the flagged import issues before saving.'));

            return;
        }

        try {
            $createdDishes = $confirmDishImport->execute($this->user(), $this->activeFamily(), $this->previewRows);
        } catch (ValidationException $exception) {
            $this->addError('preview', $exception->errors()['preview'][0] ?? __('Resolve the flagged import issues before saving.'));

            return;
        }

        $count = count($createdDishes);

        $this->reset('markdown', 'previewRows', 'hasPreview', 'previewIsValid');
        $this->resetValidation();

        Flux::toast(variant: 'success', text: trans_choice('{1} Imported :count dish.|[2,*] Imported :count dishes.', $count, ['count' => $count]));
        $this->redirectRoute('dishes.index', navigate: true);
    }

    private function validatePreview(ValidateDishImportPreview $validateDishImportPreview): void
    {
        $validatedPreview = $validateDishImportPreview->execute($this->activeFamily(), $this->previewRows);

        $this->previewRows = $validatedPreview['rows'];
        $this->previewIsValid = $validatedPreview['is_valid'];
        $this->resetValidation('preview');
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
            <flux:heading size="xl">{{ __('Import dishes') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Paste structured notes for :family, review the preview, then save everything at once.', ['family' => $this->activeFamily()->name]) }}
            </flux:text>
        </div>

        <flux:button :href="route('dishes.index')" wire:navigate>
            {{ __('Back to dishes') }}
        </flux:button>
    </div>

    <flux:card class="flex flex-col gap-4">
        <flux:field>
            <flux:label>{{ __('Markdown notes') }}</flux:label>
            <flux:textarea
                rows="12"
                wire:model="markdown"
                placeholder="## Meatloaf&#10;* Hamburger (beef)&#10;* Eggs&#10;* Breadcrumbs"
            />
            <flux:error name="markdown" />
        </flux:field>

        <div class="flex flex-wrap gap-2">
            <flux:button variant="primary" wire:click="previewImport" wire:loading.attr="disabled">
                {{ __('Create preview') }}
            </flux:button>

            @if ($hasPreview)
                <flux:button wire:click="cancelPreview" wire:loading.attr="disabled">
                    {{ __('Cancel preview') }}
                </flux:button>
            @endif
        </div>
    </flux:card>

    @if ($hasPreview)
        <section class="flex flex-col gap-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:heading>{{ __('Review import preview') }}</flux:heading>
                    <flux:text>{{ __('Edit names, notes, ingredients, categories, and the one main protein before saving.') }}</flux:text>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($previewIsValid)
                        <flux:badge color="green">{{ __('Ready to import') }}</flux:badge>
                    @else
                        <flux:badge color="red">{{ __('Needs review') }}</flux:badge>
                    @endif

                    <flux:button variant="primary" wire:click="confirmImport" wire:loading.attr="disabled">
                        {{ __('Save import') }}
                    </flux:button>
                </div>
            </div>

            <flux:error name="preview" />

            @forelse ($previewRows as $dishIndex => $row)
                <flux:card wire:key="preview-dish-{{ $dishIndex }}" class="flex flex-col gap-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input
                            wire:model.live.debounce.300ms="previewRows.{{ $dishIndex }}.name"
                            :label="__('Dish name')"
                        />

                        <flux:field>
                            <flux:label>{{ __('Optional note') }}</flux:label>
                            <flux:textarea rows="3" wire:model.live.debounce.300ms="previewRows.{{ $dishIndex }}.note" />
                        </flux:field>
                    </div>

                    @if (($row['errors'] ?? []) !== [])
                        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">
                            <div class="font-medium">{{ __('Fix before importing') }}</div>
                            <ul class="mt-2 list-disc space-y-1 ps-5">
                                @foreach ($row['errors'] as $error)
                                    <li wire:key="preview-dish-{{ $dishIndex }}-error-{{ $loop->index }}">{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (($row['warnings'] ?? []) !== [])
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                            <div class="font-medium">{{ __('Review suggestions') }}</div>
                            <ul class="mt-2 list-disc space-y-1 ps-5">
                                @foreach (array_unique($row['warnings']) as $warning)
                                    <li wire:key="preview-dish-{{ $dishIndex }}-warning-{{ $loop->index }}">{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="flex flex-col gap-3">
                        <div class="flex items-center justify-between gap-3">
                            <flux:text class="font-medium">{{ __('Ingredients') }}</flux:text>
                            <flux:button size="sm" wire:click="addIngredient({{ $dishIndex }})" wire:loading.attr="disabled">
                                {{ __('Add ingredient') }}
                            </flux:button>
                        </div>

                        <div class="grid gap-3">
                            @foreach ($row['ingredients'] as $ingredientIndex => $ingredient)
                                <div wire:key="preview-dish-{{ $dishIndex }}-ingredient-{{ $ingredientIndex }}" class="grid gap-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700 md:grid-cols-[minmax(0,1fr)_12rem_auto_auto] md:items-end">
                                    <flux:input
                                        wire:model.live.debounce.300ms="previewRows.{{ $dishIndex }}.ingredients.{{ $ingredientIndex }}.name"
                                        :label="__('Ingredient name')"
                                    />

                                    <flux:select
                                        wire:model.live="previewRows.{{ $dishIndex }}.ingredients.{{ $ingredientIndex }}.protein_category"
                                        :label="__('Protein category')"
                                    >
                                        <flux:select.option value="">{{ __('None') }}</flux:select.option>
                                        @foreach (ProteinCategory::cases() as $category)
                                            <flux:select.option wire:key="preview-dish-{{ $dishIndex }}-ingredient-{{ $ingredientIndex }}-category-{{ $category->value }}" :value="$category->value">
                                                {{ $category->label() }}
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    <label class="flex min-h-10 items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                                        <input
                                            type="radio"
                                            name="main-protein-{{ $dishIndex }}"
                                            @checked($ingredient['is_main_protein'])
                                            wire:click="markMainProtein({{ $dishIndex }}, {{ $ingredientIndex }})"
                                            class="size-4"
                                        >
                                        {{ __('Main') }}
                                    </label>

                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        wire:click="removeIngredient({{ $dishIndex }}, {{ $ingredientIndex }})"
                                        wire:loading.attr="disabled"
                                    >
                                        {{ __('Remove') }}
                                    </flux:button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </flux:card>
            @empty
                <flux:card>
                    <flux:text>{{ __('No dishes were found. Add headings like ## Meatloaf and ingredient bullets beneath them.') }}</flux:text>
                </flux:card>
            @endforelse
        </section>
    @endif
</div>
