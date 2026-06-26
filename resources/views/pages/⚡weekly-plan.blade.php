<?php

use App\Actions\Families\ResolveActiveFamily;
use App\Actions\WeeklyPlans\FindOrCreateWeeklyPlan;
use App\Actions\WeeklyPlans\RegenerateWeeklyPlanSuggestions;
use App\Actions\WeeklyPlans\ResolveWeeklyPlanWeek;
use App\Models\Dish;
use App\Models\Family;
use App\Models\User;
use App\Models\WeeklyPlan;
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
