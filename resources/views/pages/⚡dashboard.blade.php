<?php

use App\Actions\Families\ResolveActiveFamily;
use App\Actions\Families\SwitchActiveFamily;
use App\Actions\GroceryLists\ReconcileGroceryList;
use App\Actions\WeeklyPlans\FindOrCreateWeeklyPlan;
use App\Models\Family;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use App\ProteinCategory;
use App\WeeklyPlanEntrySlot;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Locked]
    public ?int $activeFamilyId = null;

    #[Locked]
    public ?int $weeklyPlanId = null;

    public ?int $selectedFamilyId = null;

    public string $weekStartDate = '';

    public function mount(
        ResolveActiveFamily $resolveActiveFamily,
        FindOrCreateWeeklyPlan $findOrCreateWeeklyPlan,
        ReconcileGroceryList $reconcileGroceryList,
    ): void {
        Gate::authorize('viewAny', Family::class);

        $family = $resolveActiveFamily->execute($this->user());

        if (is_null($family)) {
            $this->redirectRoute('families.index', navigate: true);

            return;
        }

        $this->setDashboardFamily($family, $findOrCreateWeeklyPlan, $reconcileGroceryList);
    }

    public function switchFamily(
        SwitchActiveFamily $switchActiveFamily,
        FindOrCreateWeeklyPlan $findOrCreateWeeklyPlan,
        ReconcileGroceryList $reconcileGroceryList,
    ): void {
        if (is_null($this->selectedFamilyId) || $this->selectedFamilyId === $this->activeFamilyId) {
            return;
        }

        $family = Family::query()->findOrFail($this->selectedFamilyId);

        $switchActiveFamily->execute($this->user(), $family);
        $this->setDashboardFamily($family, $findOrCreateWeeklyPlan, $reconcileGroceryList);

        Flux::toast(variant: 'success', text: __('Active family changed.'));
    }

    public function dismissChecklist(): void
    {
        $family = $this->activeFamily();

        Gate::authorize('view', $family);

        $family->members()->updateExistingPivot($this->user()->id, [
            'onboarding_checklist_dismissed_at' => now(),
        ]);

        unset($this->checklistDismissed, $this->showChecklist);
    }

    public function weekLabel(): string
    {
        $weekStart = Carbon::parse($this->weekStartDate, $this->activeFamily()->timezone)->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(4);

        return $weekStart->format('M j').'–'.$weekEnd->format('M j, Y');
    }

    /**
     * @return Collection<int, array{weekday: int, label: string, main: string|null, alternative: string|null}>
     */
    #[Computed]
    public function dinnerSummary(): Collection
    {
        return collect($this->weekdayLabels())
            ->map(function (string $label, int $weekday): array {
                $entries = $this->entriesByWeekday->get($weekday, collect());
                $mainEntry = $entries->first(fn (WeeklyPlanEntry $entry): bool => $entry->slot === WeeklyPlanEntrySlot::Main);
                $alternativeEntry = $entries->first(fn (WeeklyPlanEntry $entry): bool => $entry->slot === WeeklyPlanEntrySlot::Alternative);

                return [
                    'weekday' => $weekday,
                    'label' => $label,
                    'main' => $mainEntry?->label(),
                    'alternative' => $alternativeEntry?->label(),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, Collection<int, WeeklyPlanEntry>>
     */
    #[Computed]
    public function entriesByWeekday(): Collection
    {
        return $this->weeklyPlan()
            ->entries()
            ->with('dish')
            ->orderBy('weekday')
            ->orderBy('slot')
            ->get()
            ->groupBy('weekday');
    }

    /**
     * @return array{unchecked: int, total: int}
     */
    #[Computed]
    public function groceryProgress(): array
    {
        $activeItems = $this->weeklyPlan()
            ->groceryList()
            ->firstOrCreate()
            ->items()
            ->where('is_suppressed', false);

        return [
            'unchecked' => (clone $activeItems)->where('is_checked', false)->count(),
            'total' => $activeItems->count(),
        ];
    }

    /**
     * @return EloquentCollection<int, Family>
     */
    #[Computed]
    public function families(): EloquentCollection
    {
        return $this->user()
            ->families()
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, array{category: ProteinCategory, label: string, count: int, complete: bool}>
     */
    #[Computed]
    public function checklistProgress(): Collection
    {
        return collect(ProteinCategory::cases())
            ->map(function (ProteinCategory $category): array {
                $activeDishCount = $this->activeDishCountFor($category);

                return [
                    'category' => $category,
                    'label' => $category->label(),
                    'count' => $activeDishCount,
                    'complete' => $activeDishCount >= 2,
                ];
            });
    }

    #[Computed]
    public function checklistDismissed(): bool
    {
        $family = $this->user()
            ->families()
            ->whereKey($this->activeFamilyId)
            ->firstOrFail();

        return ! is_null($family->pivot->onboarding_checklist_dismissed_at);
    }

    #[Computed]
    public function showChecklist(): bool
    {
        return ! $this->checklistDismissed
            && $this->checklistProgress->contains(fn (array $progress): bool => ! $progress['complete']);
    }

    private function activeFamily(): Family
    {
        return $this->user()->families()->findOrFail($this->activeFamilyId);
    }

    private function weeklyPlan(): WeeklyPlan
    {
        return $this->activeFamily()->weeklyPlans()->findOrFail($this->weeklyPlanId);
    }

    private function setDashboardFamily(
        Family $family,
        FindOrCreateWeeklyPlan $findOrCreateWeeklyPlan,
        ReconcileGroceryList $reconcileGroceryList,
    ): void {
        Gate::authorize('view', $family);

        $this->activeFamilyId = $family->id;
        $this->selectedFamilyId = $family->id;

        $weekStart = $this->defaultWeekStart($family);
        $weeklyPlan = $findOrCreateWeeklyPlan->execute($this->user(), $family, $weekStart);
        $reconcileGroceryList->execute($weeklyPlan);

        $this->weeklyPlanId = $weeklyPlan->id;
        $this->weekStartDate = $weekStart->toDateString();

        unset(
            $this->families,
            $this->dinnerSummary,
            $this->entriesByWeekday,
            $this->groceryProgress,
            $this->checklistProgress,
            $this->checklistDismissed,
            $this->showChecklist,
        );
    }

    private function defaultWeekStart(Family $family): Carbon
    {
        $now = Carbon::now($family->timezone)->startOfDay();
        $weekStart = $now->copy()->startOfWeek(CarbonInterface::MONDAY);

        if ($now->isWeekend()) {
            return $weekStart->addWeek();
        }

        return $weekStart;
    }

    private function activeDishCountFor(ProteinCategory $category): int
    {
        return $this->activeFamily()
            ->dishes()
            ->active()
            ->whereHas('mainProtein', fn ($query) => $query->where('protein_category', $category))
            ->count();
    }

    /**
     * @return array<int, string>
     */
    private function weekdayLabels(): array
    {
        return [
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
        ];
    }

    private function user(): User
    {
        return Auth::user();
    }
};
?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-8">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Active family: :family', ['family' => $this->activeFamily()->name]) }}
            </flux:text>
        </div>

        <form class="flex flex-col gap-3 sm:flex-row sm:items-end" wire:submit="switchFamily">
            <flux:field>
                <flux:label>{{ __('Family') }}</flux:label>
                <flux:select wire:model="selectedFamilyId">
                    @foreach ($this->families as $family)
                        <flux:select.option wire:key="dashboard-family-{{ $family->id }}" value="{{ $family->id }}">
                            {{ $family->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:button type="submit" wire:loading.attr="disabled">
                {{ __('Switch') }}
            </flux:button>
        </form>
    </div>

    <section class="grid gap-4 lg:grid-cols-3">
        <flux:card class="flex flex-col gap-4 lg:col-span-2">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <flux:heading>{{ __('Weekday dinners') }}</flux:heading>
                    <flux:text>{{ $this->weekLabel() }}</flux:text>
                </div>

                <flux:button :href="route('weekly-plans.show', ['weekStart' => $weekStartDate])" icon="calendar-days" wire:navigate>
                    {{ __('Plan dinners') }}
                </flux:button>
            </div>

            <div class="grid gap-3 md:grid-cols-5">
                @foreach ($this->dinnerSummary as $summary)
                    <div wire:key="dashboard-weekday-{{ $summary['weekday'] }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:text class="font-medium">{{ $summary['label'] }}</flux:text>

                        @if ($summary['main'])
                            <flux:text class="mt-2">{{ $summary['main'] }}</flux:text>
                        @else
                            <flux:text class="mt-2 text-sm">{{ __('No main dish planned') }}</flux:text>
                        @endif

                        @if ($summary['alternative'])
                            <flux:text class="mt-1 text-sm">{{ __('Alt: :dish', ['dish' => $summary['alternative']]) }}</flux:text>
                        @endif
                    </div>
                @endforeach
            </div>
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading>{{ __('Grocery progress') }}</flux:heading>
                <flux:text>
                    {{ __(':unchecked unchecked of :total active items', ['unchecked' => $this->groceryProgress['unchecked'], 'total' => $this->groceryProgress['total']]) }}
                </flux:text>
            </div>

            @php
                $completedItems = max($this->groceryProgress['total'] - $this->groceryProgress['unchecked'], 0);
                $completionPercent = $this->groceryProgress['total'] > 0
                    ? (int) round(($completedItems / $this->groceryProgress['total']) * 100)
                    : 0;
            @endphp

            <flux:progress :value="$completionPercent" />

            <flux:button :href="route('grocery-lists.show', ['weekStart' => $weekStartDate])" icon="shopping-cart" wire:navigate>
                {{ __('Open groceries') }}
            </flux:button>
        </flux:card>
    </section>

    <section class="grid gap-4 md:grid-cols-3">
        <flux:button :href="route('weekly-plans.show', ['weekStart' => $weekStartDate])" icon="calendar-days" wire:navigate>
            {{ __('Planning') }}
        </flux:button>

        <flux:button :href="route('grocery-lists.show', ['weekStart' => $weekStartDate])" icon="shopping-cart" wire:navigate>
            {{ __('Groceries') }}
        </flux:button>

        <flux:button :href="route('dishes.index')" icon="book-open" wire:navigate>
            {{ __('Dish collection') }}
        </flux:button>
    </section>

    @if ($this->showChecklist)
        <flux:card class="flex flex-col gap-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <flux:heading>{{ __('Dish collection checklist') }}</flux:heading>
                    <flux:text>{{ __('Aim for two active dishes in every category before relying on balanced suggestions.') }}</flux:text>
                </div>

                <flux:button variant="ghost" wire:click="dismissChecklist" wire:loading.attr="disabled">
                    {{ __('Dismiss') }}
                </flux:button>
            </div>

            <div class="grid gap-3 md:grid-cols-5">
                @foreach ($this->checklistProgress as $progress)
                    <div wire:key="checklist-{{ $progress['category']->value }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex items-start justify-between gap-2">
                            <flux:text class="font-medium">{{ $progress['label'] }}</flux:text>

                            @if ($progress['complete'])
                                <flux:badge color="green">{{ __('Ready') }}</flux:badge>
                            @else
                                <flux:badge color="amber">{{ __(':count/2', ['count' => min($progress['count'], 2)]) }}</flux:badge>
                            @endif
                        </div>

                        <flux:text class="mt-2 text-sm">
                            {{ trans_choice(':count active dish|:count active dishes', $progress['count'], ['count' => $progress['count']]) }}
                        </flux:text>
                    </div>
                @endforeach
            </div>

            <div>
                <flux:button :href="route('dishes.index')" icon="plus" wire:navigate>
                    {{ __('Add dishes') }}
                </flux:button>
            </div>
        </flux:card>
    @endif
</div>
