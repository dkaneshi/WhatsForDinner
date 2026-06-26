<?php

use App\Actions\Families\ResolveActiveFamily;
use App\Actions\WeeklyPlans\FindOrCreateWeeklyPlan;
use App\Actions\WeeklyPlans\RemoveWeeklyPlanEntry;
use App\Actions\WeeklyPlans\RegenerateWeeklyPlanSuggestions;
use App\Actions\WeeklyPlans\ResolveWeeklyPlanWeek;
use App\Actions\WeeklyPlans\ScheduleWeeklyPlanEntry;
use App\Models\Dish;
use App\Models\Family;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use App\WeeklyPlanEntrySlot;
use App\WeeklyPlanSpecialEntry;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Weekly Plan')] class extends Component {
    #[Locked]
    public ?int $activeFamilyId = null;

    #[Locked]
    public ?int $weeklyPlanId = null;

    public string $weekStartDate = '';

    public bool $isPast = false;

    public array $dishSelections = [];

    public array $specialSelections = [];

    public function mount(
        ResolveActiveFamily $resolveActiveFamily,
        ResolveWeeklyPlanWeek $resolveWeeklyPlanWeek,
        FindOrCreateWeeklyPlan $findOrCreateWeeklyPlan,
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
        $this->setSelectionDefaults();

        if (! $this->isPast && $weeklyPlan->suggestions()->doesntExist()) {
            app(RegenerateWeeklyPlanSuggestions::class)->execute($this->user(), $weeklyPlan);
        }
    }

    public function regenerateSuggestions(RegenerateWeeklyPlanSuggestions $regenerateWeeklyPlanSuggestions): void
    {
        $regenerateWeeklyPlanSuggestions->execute($this->user(), $this->weeklyPlan());
        unset($this->suggestedDishes);

        Flux::toast(variant: 'success', text: __('Suggestions regenerated.'));
    }

    public function scheduleDish(int $weekday, string $slot, ScheduleWeeklyPlanEntry $scheduleWeeklyPlanEntry): void
    {
        $slot = WeeklyPlanEntrySlot::from($slot);
        $dishId = (int) data_get($this->dishSelections, "{$weekday}.{$slot->value}", 0);

        if ($dishId < 1) {
            $this->addError("dishSelections.{$weekday}.{$slot->value}", __('Choose a dish.'));

            return;
        }

        $dish = $this->activeFamily()->dishes()->active()->find($dishId);

        if (! $dish instanceof Dish) {
            $this->addError("dishSelections.{$weekday}.{$slot->value}", __('Choose an active dish from this family.'));

            return;
        }

        $scheduleWeeklyPlanEntry->execute($this->user(), $this->weeklyPlan(), $weekday, $slot, dish: $dish);
        data_set($this->dishSelections, "{$weekday}.{$slot->value}", '');
        $this->refreshPlanData();

        Flux::toast(variant: 'success', text: __('Dinner scheduled.'));
    }

    public function scheduleSpecial(int $weekday, ScheduleWeeklyPlanEntry $scheduleWeeklyPlanEntry): void
    {
        $specialEntry = WeeklyPlanSpecialEntry::tryFrom((string) data_get($this->specialSelections, "{$weekday}.main"));

        if (! $specialEntry instanceof WeeklyPlanSpecialEntry) {
            $this->addError("specialSelections.{$weekday}.main", __('Choose a special dinner night.'));

            return;
        }

        $scheduleWeeklyPlanEntry->execute(
            user: $this->user(),
            weeklyPlan: $this->weeklyPlan(),
            weekday: $weekday,
            slot: WeeklyPlanEntrySlot::Main,
            specialEntry: $specialEntry,
        );

        data_set($this->specialSelections, "{$weekday}.main", WeeklyPlanSpecialEntry::EatOut->value);
        $this->refreshPlanData();

        Flux::toast(variant: 'success', text: __('Special dinner night scheduled.'));
    }

    public function removeEntry(int $entryId, RemoveWeeklyPlanEntry $removeWeeklyPlanEntry): void
    {
        $entry = $this->weeklyPlan()->entries()->findOrFail($entryId);

        $removeWeeklyPlanEntry->execute($this->user(), $entry);
        $this->refreshPlanData();

        Flux::toast(variant: 'success', text: __('Dinner removed.'));
    }

    public function markFresh(int $entryId): void
    {
        $entry = $this->weeklyPlan()->entries()->findOrFail($entryId);

        Gate::authorize('update', $entry->weeklyPlan);

        $entry->update(['is_leftovers' => false]);
        $this->refreshPlanData();

        Flux::toast(variant: 'success', text: __('Marked as cooked fresh.'));
    }

    public function previousWeekUrl(): string
    {
        $resolveWeeklyPlanWeek = app(ResolveWeeklyPlanWeek::class);

        return route('weekly-plans.show', [
            'weekStart' => $resolveWeeklyPlanWeek
                ->previousWeek($this->weekStart())
                ->toDateString(),
        ]);
    }

    public function currentWeekUrl(): string
    {
        $resolveWeeklyPlanWeek = app(ResolveWeeklyPlanWeek::class);

        return route('weekly-plans.show', [
            'weekStart' => $resolveWeeklyPlanWeek
                ->currentWeekStart($this->activeFamily())
                ->toDateString(),
        ]);
    }

    public function nextWeekUrl(): string
    {
        $resolveWeeklyPlanWeek = app(ResolveWeeklyPlanWeek::class);

        return route('weekly-plans.show', [
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

    public function completionLabel(): string
    {
        $filledMainSlots = $this->weeklyPlan()
            ->entries()
            ->where('slot', WeeklyPlanEntrySlot::Main)
            ->count();

        return __(':filled of 5 main dinners planned', ['filled' => $filledMainSlots]);
    }

    /**
     * @return array<int, string>
     */
    public function weekdayLabels(): array
    {
        return [
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
        ];
    }

    /**
     * @return Collection<int, Dish>
     */
    #[Computed]
    public function availableDishes(): Collection
    {
        return $this->activeFamily()
            ->dishes()
            ->active()
            ->orderBy('name')
            ->get();
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
     * @return Collection<int, Dish>
     */
    #[Computed]
    public function suggestedDishes(): Collection
    {
        return $this->weeklyPlan()
            ->suggestions()
            ->with(['dish.ingredients' => fn ($query) => $query->orderBy('name'), 'dish.mainProtein'])
            ->get()
            ->map(fn ($suggestion): Dish => $suggestion->dish);
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

    private function setSelectionDefaults(): void
    {
        foreach (array_keys($this->weekdayLabels()) as $weekday) {
            $this->dishSelections[$weekday] ??= [];
            $this->dishSelections[$weekday][WeeklyPlanEntrySlot::Main->value] ??= '';
            $this->dishSelections[$weekday][WeeklyPlanEntrySlot::Alternative->value] ??= '';
            $this->specialSelections[$weekday] ??= [];
            $this->specialSelections[$weekday][WeeklyPlanEntrySlot::Main->value] ??= WeeklyPlanSpecialEntry::EatOut->value;
        }
    }

    private function refreshPlanData(): void
    {
        $this->setSelectionDefaults();

        unset($this->entriesByWeekday, $this->suggestedDishes);
    }

    private function user(): User
    {
        return Auth::user();
    }
};
?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-8">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Weekly plan') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Plan dinners for :family using :timezone week boundaries.', ['family' => $this->activeFamily()->name, 'timezone' => $this->activeFamily()->timezone]) }}
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
                <flux:button :href="$this->previousWeekUrl()" wire:navigate>
                    {{ __('Previous') }}
                </flux:button>

                <flux:button :href="$this->currentWeekUrl()" wire:navigate>
                    {{ __('Current') }}
                </flux:button>

                <flux:button :href="$this->nextWeekUrl()" wire:navigate>
                    {{ __('Next') }}
                </flux:button>
            </div>
        </div>

        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <flux:heading>{{ __('Dinner plan') }}</flux:heading>
                    <flux:text>{{ $this->completionLabel() }}</flux:text>
                </div>

                @if ($this->weeklyPlan()->isComplete())
                    <flux:badge color="green">{{ __('Week complete') }}</flux:badge>
                @else
                    <flux:badge color="amber">{{ __('In progress') }}</flux:badge>
                @endif
            </div>

            <div class="grid gap-4 lg:grid-cols-5">
                @foreach ($this->weekdayLabels() as $weekday => $weekdayLabel)
                    @php
                        $entries = $this->entriesByWeekday->get($weekday, collect());
                        $mainEntry = $entries->first(fn (WeeklyPlanEntry $entry): bool => $entry->slot === WeeklyPlanEntrySlot::Main);
                        $alternativeEntry = $entries->first(fn (WeeklyPlanEntry $entry): bool => $entry->slot === WeeklyPlanEntrySlot::Alternative);
                        $mainIsSpecial = $mainEntry?->special_entry instanceof WeeklyPlanSpecialEntry;
                    @endphp

                    <flux:card wire:key="weekday-{{ $weekday }}" class="flex flex-col gap-4">
                        <div>
                            <flux:heading size="lg">{{ $weekdayLabel }}</flux:heading>
                        </div>

                        <div class="flex flex-col gap-3">
                            <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <flux:text class="font-medium">{{ __('Main') }}</flux:text>
                                </div>

                                @if ($mainEntry)
                                    <div class="flex flex-col gap-2">
                                        <flux:text>{{ $mainEntry->label() }}</flux:text>

                                        @if ($mainEntry->ingredientNames() !== [])
                                            <flux:text class="text-sm">{{ __('Ingredients: :ingredients', ['ingredients' => collect($mainEntry->ingredientNames())->join(', ')]) }}</flux:text>
                                        @endif

                                        <div class="flex flex-wrap gap-2">
                                            @if (! $isPast && $mainEntry->is_leftovers)
                                                <flux:button size="sm" variant="ghost" wire:click="markFresh({{ $mainEntry->id }})" wire:loading.attr="disabled">
                                                    {{ __('Cook fresh') }}
                                                </flux:button>
                                            @endif

                                            @if (! $isPast)
                                                <flux:button size="sm" variant="danger" wire:click="removeEntry({{ $mainEntry->id }})" wire:loading.attr="disabled">
                                                    {{ __('Remove') }}
                                                </flux:button>
                                            @endif
                                        </div>
                                    </div>
                                @elseif (! $isPast)
                                    <div class="flex flex-col gap-3">
                                        <flux:field>
                                            <flux:label>{{ __('Dish') }}</flux:label>
                                            <flux:select wire:model="dishSelections.{{ $weekday }}.main">
                                                <flux:select.option value="">{{ __('Choose dish') }}</flux:select.option>
                                                @foreach ($this->availableDishes as $dish)
                                                    <flux:select.option wire:key="main-dish-{{ $weekday }}-{{ $dish->id }}" value="{{ $dish->id }}">
                                                        {{ $dish->name }}
                                                    </flux:select.option>
                                                @endforeach
                                            </flux:select>
                                            <flux:error name="dishSelections.{{ $weekday }}.main" />
                                        </flux:field>

                                        <flux:button size="sm" wire:click="scheduleDish({{ $weekday }}, 'main')" wire:loading.attr="disabled">
                                            {{ __('Add main dish') }}
                                        </flux:button>

                                        <div class="border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                            <flux:field>
                                                <flux:label>{{ __('Special night') }}</flux:label>
                                                <flux:select wire:model="specialSelections.{{ $weekday }}.main">
                                                    @foreach (WeeklyPlanSpecialEntry::cases() as $specialEntry)
                                                        <flux:select.option value="{{ $specialEntry->value }}">{{ $specialEntry->label() }}</flux:select.option>
                                                    @endforeach
                                                </flux:select>
                                            </flux:field>

                                            <flux:button class="mt-3" size="sm" variant="ghost" wire:click="scheduleSpecial({{ $weekday }})" wire:loading.attr="disabled">
                                                {{ __('Add special') }}
                                            </flux:button>
                                        </div>
                                    </div>
                                @else
                                    <flux:text>{{ __('No main dish planned.') }}</flux:text>
                                @endif
                            </div>

                            <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <flux:text class="font-medium">{{ __('Alternative') }}</flux:text>
                                </div>

                                @if ($alternativeEntry)
                                    <div class="flex flex-col gap-2">
                                        <flux:text>{{ $alternativeEntry->label() }}</flux:text>

                                        @if ($alternativeEntry->ingredientNames() !== [])
                                            <flux:text class="text-sm">{{ __('Ingredients: :ingredients', ['ingredients' => collect($alternativeEntry->ingredientNames())->join(', ')]) }}</flux:text>
                                        @endif

                                        <div class="flex flex-wrap gap-2">
                                            @if (! $isPast && $alternativeEntry->is_leftovers)
                                                <flux:button size="sm" variant="ghost" wire:click="markFresh({{ $alternativeEntry->id }})" wire:loading.attr="disabled">
                                                    {{ __('Cook fresh') }}
                                                </flux:button>
                                            @endif

                                            @if (! $isPast)
                                                <flux:button size="sm" variant="danger" wire:click="removeEntry({{ $alternativeEntry->id }})" wire:loading.attr="disabled">
                                                    {{ __('Remove') }}
                                                </flux:button>
                                            @endif
                                        </div>
                                    </div>
                                @elseif ($mainIsSpecial)
                                    <flux:text>{{ __('Not used for special nights.') }}</flux:text>
                                @elseif (! $isPast)
                                    <div class="flex flex-col gap-3">
                                        <flux:field>
                                            <flux:label>{{ __('Dish') }}</flux:label>
                                            <flux:select wire:model="dishSelections.{{ $weekday }}.alternative">
                                                <flux:select.option value="">{{ __('Choose dish') }}</flux:select.option>
                                                @foreach ($this->availableDishes as $dish)
                                                    <flux:select.option wire:key="alternative-dish-{{ $weekday }}-{{ $dish->id }}" value="{{ $dish->id }}">
                                                        {{ $dish->name }}
                                                    </flux:select.option>
                                                @endforeach
                                            </flux:select>
                                            <flux:error name="dishSelections.{{ $weekday }}.alternative" />
                                        </flux:field>

                                        <flux:button size="sm" variant="ghost" wire:click="scheduleDish({{ $weekday }}, 'alternative')" wire:loading.attr="disabled">
                                            {{ __('Add alternative') }}
                                        </flux:button>
                                    </div>
                                @else
                                    <flux:text>{{ __('No alternative dish planned.') }}</flux:text>
                                @endif
                            </div>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        </div>

        <flux:separator />

        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <flux:heading>{{ __('Suggestions') }}</flux:heading>
                    <flux:text>{{ __('Ten balanced ideas when your dish collection has enough options.') }}</flux:text>
                </div>

                @if (! $isPast)
                    <flux:button wire:click="regenerateSuggestions" wire:loading.attr="disabled">
                        {{ __('Regenerate suggestions') }}
                    </flux:button>
                @endif
            </div>

            @if ($this->suggestedDishes->isEmpty())
                <div class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-700">
                    <flux:text class="font-medium">{{ __('No suggestions yet.') }}</flux:text>
                    <flux:text class="mt-1">
                        {{ __('Add active dishes with main protein categories to build balanced suggestions.') }}
                    </flux:text>
                </div>
            @else
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($this->suggestedDishes as $dish)
                        <flux:card wire:key="suggestion-{{ $dish->id }}" class="flex flex-col gap-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <flux:heading>{{ $dish->name }}</flux:heading>

                                    @if ($dish->proteinCategory())
                                        <flux:badge class="mt-2">{{ $dish->proteinCategory()->label() }}</flux:badge>
                                    @endif
                                </div>
                            </div>

                            @if ($dish->note)
                                <flux:text>{{ $dish->note }}</flux:text>
                            @endif

                            <div>
                                <flux:text class="font-medium">{{ __('Ingredients') }}</flux:text>
                                <flux:text>{{ $dish->ingredients->pluck('name')->join(', ') }}</flux:text>
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            @endif
        </div>
    </flux:card>
</div>
