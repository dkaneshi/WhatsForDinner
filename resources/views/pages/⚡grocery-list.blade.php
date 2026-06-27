<?php

use App\Actions\Families\ResolveActiveFamily;
use App\Actions\GroceryLists\AddManualGroceryItem;
use App\Actions\GroceryLists\ReconcileGroceryList;
use App\Actions\GroceryLists\UpdateGroceryItemState;
use App\Actions\WeeklyPlans\FindOrCreateWeeklyPlan;
use App\Actions\WeeklyPlans\ResolveWeeklyPlanWeek;
use App\Models\Family;
use App\Models\GroceryList;
use App\Models\GroceryListItem;
use App\Models\User;
use App\Models\WeeklyPlan;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Grocery List')] class extends Component {
    #[Locked]
    public ?int $activeFamilyId = null;

    #[Locked]
    public ?int $weeklyPlanId = null;

    #[Locked]
    public ?int $groceryListId = null;

    public string $weekStartDate = '';

    public bool $isPast = false;

    public string $manualItemName = '';

    public function mount(
        ResolveActiveFamily $resolveActiveFamily,
        ResolveWeeklyPlanWeek $resolveWeeklyPlanWeek,
        FindOrCreateWeeklyPlan $findOrCreateWeeklyPlan,
        ReconcileGroceryList $reconcileGroceryList,
        ?string $weekStart = null,
    ): void {
        $family = $resolveActiveFamily->execute($this->user());

        if (is_null($family)) {
            $this->redirectRoute('families.index', navigate: true);

            return;
        }

        Gate::authorize('view', $family);

        $this->activeFamilyId = $family->id;

        $week = $resolveWeeklyPlanWeek->fromRouteValue($family, $weekStart);
        $weeklyPlan = $findOrCreateWeeklyPlan->execute($this->user(), $family, $week);

        Gate::authorize('view', $weeklyPlan);

        $this->weeklyPlanId = $weeklyPlan->id;
        $this->weekStartDate = $week->toDateString();
        $this->isPast = $resolveWeeklyPlanWeek->isPastWeek($family, $week);

        $groceryList = $this->isPast
            ? $weeklyPlan->groceryList()->firstOrCreate()
            : $reconcileGroceryList->execute($weeklyPlan);

        $this->groceryListId = $groceryList->id;
    }

    public function addManualItem(AddManualGroceryItem $addManualGroceryItem): void
    {
        $this->resetValidation();

        $addManualGroceryItem->execute($this->groceryList(), $this->manualItemName);
        $this->manualItemName = '';
        $this->refreshGroceryItems();

        Flux::toast(variant: 'success', text: __('Manual item added.'));
    }

    public function toggleItem(int $itemId, UpdateGroceryItemState $updateGroceryItemState): void
    {
        $item = $this->groceryList()->items()->findOrFail($itemId);

        $updateGroceryItemState->setChecked($item, ! $item->is_checked);
        $this->refreshGroceryItems();
    }

    public function removeItem(int $itemId, UpdateGroceryItemState $updateGroceryItemState): void
    {
        $item = $this->groceryList()->items()->findOrFail($itemId);

        $updateGroceryItemState->remove($item);
        $this->refreshGroceryItems();

        Flux::toast(variant: 'success', text: __('Grocery item removed.'));
    }

    public function restoreItem(int $itemId, UpdateGroceryItemState $updateGroceryItemState): void
    {
        $item = $this->groceryList()->items()->findOrFail($itemId);

        $updateGroceryItemState->restore($item);
        $this->refreshGroceryItems();

        Flux::toast(variant: 'success', text: __('Grocery item restored.'));
    }

    public function previousWeekUrl(): string
    {
        $resolveWeeklyPlanWeek = app(ResolveWeeklyPlanWeek::class);

        return route('grocery-lists.show', [
            'weekStart' => $resolveWeeklyPlanWeek
                ->previousWeek($this->weekStart())
                ->toDateString(),
        ]);
    }

    public function currentWeekUrl(): string
    {
        $resolveWeeklyPlanWeek = app(ResolveWeeklyPlanWeek::class);

        return route('grocery-lists.show', [
            'weekStart' => $resolveWeeklyPlanWeek
                ->currentWeekStart($this->activeFamily())
                ->toDateString(),
        ]);
    }

    public function nextWeekUrl(): string
    {
        $resolveWeeklyPlanWeek = app(ResolveWeeklyPlanWeek::class);

        return route('grocery-lists.show', [
            'weekStart' => $resolveWeeklyPlanWeek
                ->nextWeek($this->weekStart())
                ->toDateString(),
        ]);
    }

    public function weekLabel(): string
    {
        $weekStart = $this->weekStart();
        $weekEnd = $weekStart->copy()->addDays(4);

        return $weekStart->format('M j').'–'.$weekEnd->format('M j, Y');
    }

    /** @return Collection<int, GroceryListItem> */
    #[Computed]
    public function activeItems(): Collection
    {
        return $this->groceryList()
            ->items()
            ->where('is_suppressed', false)
            ->orderBy('is_checked')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, GroceryListItem> */
    #[Computed]
    public function incompleteItems(): Collection
    {
        return $this->activeItems->where('is_checked', false)->values();
    }

    /** @return Collection<int, GroceryListItem> */
    #[Computed]
    public function completedItems(): Collection
    {
        return $this->activeItems->where('is_checked', true)->values();
    }

    /** @return Collection<int, GroceryListItem> */
    #[Computed]
    public function removedItems(): Collection
    {
        return $this->groceryList()
            ->items()
            ->where('is_suppressed', true)
            ->orderBy('name')
            ->get();
    }

    private function weekStart(): Carbon
    {
        return Carbon::parse($this->weekStartDate, $this->activeFamily()->timezone)->startOfDay();
    }

    private function activeFamily(): Family
    {
        return $this->user()->families()->findOrFail($this->activeFamilyId);
    }

    private function weeklyPlan(): WeeklyPlan
    {
        return $this->activeFamily()->weeklyPlans()->findOrFail($this->weeklyPlanId);
    }

    private function groceryList(): GroceryList
    {
        return $this->weeklyPlan()->groceryList()->findOrFail($this->groceryListId);
    }

    private function refreshGroceryItems(): void
    {
        unset($this->activeItems, $this->incompleteItems, $this->completedItems, $this->removedItems);
    }

    private function user(): User
    {
        return Auth::user();
    }
};
?>

<div class="mx-auto flex w-full max-w-4xl flex-col gap-8">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Grocery list') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('A flat list for :family based on planned dinners.', ['family' => $this->activeFamily()->name]) }}
            </flux:text>
        </div>

        @if ($isPast)
            <flux:badge color="zinc">{{ __('Read-only history') }}</flux:badge>
        @else
            <flux:badge color="green">{{ __('Editable') }}</flux:badge>
        @endif
    </div>

    <flux:card class="flex flex-col gap-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading>{{ $this->weekLabel() }}</flux:heading>
                <flux:text>{{ __('Week starts Monday, :date', ['date' => $weekStartDate]) }}</flux:text>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button :href="$this->previousWeekUrl()" wire:navigate>{{ __('Previous') }}</flux:button>
                <flux:button :href="$this->currentWeekUrl()" wire:navigate>{{ __('Current') }}</flux:button>
                <flux:button :href="$this->nextWeekUrl()" wire:navigate>{{ __('Next') }}</flux:button>
            </div>
        </div>

        @if (! $isPast)
            <form class="flex flex-col gap-3 sm:flex-row sm:items-end" wire:submit="addManualItem">
                <flux:field class="flex-1">
                    <flux:label>{{ __('Manual item') }}</flux:label>
                    <flux:input wire:model="manualItemName" placeholder="{{ __('Paper plates') }}" />
                    <flux:error name="manualItemName" />
                </flux:field>

                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    {{ __('Add item') }}
                </flux:button>
            </form>
        @endif
    </flux:card>

    <flux:card class="flex flex-col gap-4">
        <div>
            <flux:heading>{{ __('Shopping') }}</flux:heading>
            <flux:text>{{ __('Unchecked items are shown first. Measurements are intentionally omitted.') }}</flux:text>
        </div>

        @if ($this->incompleteItems->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-700">
                <flux:text>{{ __('No unchecked grocery items.') }}</flux:text>
            </div>
        @else
            <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->incompleteItems as $item)
                    <div wire:key="grocery-item-{{ $item->id }}" class="flex items-start justify-between gap-4 py-4">
                        <div class="flex min-w-0 flex-1 items-start gap-3">
                            <flux:checkbox :checked="$item->is_checked" wire:click="toggleItem({{ $item->id }})" :disabled="$isPast" />

                            <div class="min-w-0">
                                <flux:text class="font-medium">{{ $item->name }}</flux:text>

                                @if ($item->is_manual)
                                    <flux:badge class="mt-2" color="zinc">{{ __('Manual') }}</flux:badge>
                                @elseif ($item->source_labels)
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-sm text-zinc-600 dark:text-zinc-300">{{ __('Used by dinners') }}</summary>
                                        <flux:text class="mt-1 text-sm">{{ collect($item->source_labels)->join(', ') }}</flux:text>
                                    </details>
                                @endif
                            </div>
                        </div>

                        @if (! $isPast)
                            <flux:button size="sm" variant="danger" wire:click="removeItem({{ $item->id }})" wire:loading.attr="disabled">
                                {{ __('Remove') }}
                            </flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($this->completedItems->isNotEmpty())
            <details class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <summary class="cursor-pointer font-medium">{{ __('Completed (:count)', ['count' => $this->completedItems->count()]) }}</summary>

                <div class="mt-4 flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->completedItems as $item)
                        <div wire:key="completed-grocery-item-{{ $item->id }}" class="flex items-center justify-between gap-4 py-3">
                            <div class="flex items-center gap-3">
                                <flux:checkbox :checked="$item->is_checked" wire:click="toggleItem({{ $item->id }})" :disabled="$isPast" />
                                <flux:text class="line-through opacity-70">{{ $item->name }}</flux:text>
                            </div>

                            @if (! $isPast)
                                <flux:button size="sm" variant="danger" wire:click="removeItem({{ $item->id }})" wire:loading.attr="disabled">
                                    {{ __('Remove') }}
                                </flux:button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </flux:card>

    @if ($this->removedItems->isNotEmpty())
        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading>{{ __('Removed items') }}</flux:heading>
                <flux:text>{{ __('Generated items removed this week stay hidden until you restore them.') }}</flux:text>
            </div>

            <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->removedItems as $item)
                    <div wire:key="removed-grocery-item-{{ $item->id }}" class="flex items-center justify-between gap-4 py-3">
                        <flux:text>{{ $item->name }}</flux:text>

                        @if (! $isPast)
                            <flux:button size="sm" variant="ghost" wire:click="restoreItem({{ $item->id }})" wire:loading.attr="disabled">
                                {{ __('Restore') }}
                            </flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif
</div>
