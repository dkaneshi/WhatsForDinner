<?php

use App\Actions\Families\ResolveActiveFamily;
use App\Actions\WeeklyPlans\FindOrCreateWeeklyPlan;
use App\Actions\WeeklyPlans\ResolveWeeklyPlanWeek;
use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    private function weekStart(): Carbon
    {
        return Carbon::parse($this->weekStartDate, $this->activeFamily()->timezone)->startOfDay();
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

        <div class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-700">
            <flux:text class="font-medium">{{ __('Dinner scheduling comes next.') }}</flux:text>
            <flux:text class="mt-1">
                {{ __('This weekly plan record exists now, so suggestions and dinner slots can attach to it in the next implementation specs.') }}
            </flux:text>
        </div>
    </flux:card>
</div>
