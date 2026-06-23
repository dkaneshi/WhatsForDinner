<?php

use App\Actions\Families\CreateFamily;
use App\Actions\Families\ResolveActiveFamily;
use App\Actions\Families\SwitchActiveFamily;
use App\Actions\Families\UpdateFamily;
use App\Models\Family;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Families')] class extends Component {
    public string $newFamilyName = '';

    public string $newFamilyTimezone = '';

    public string $familyName = '';

    public string $familyTimezone = '';

    #[Locked]
    public ?int $activeFamilyId = null;

    public function mount(ResolveActiveFamily $resolveActiveFamily): void
    {
        Gate::authorize('viewAny', Family::class);

        $this->newFamilyTimezone = config('app.timezone');
        $this->setActiveFamily($resolveActiveFamily->execute($this->user()));
    }

    public function createFamily(CreateFamily $createFamily): void
    {
        $validated = $this->validate([
            'newFamilyName' => ['required', 'string', 'max:100'],
            'newFamilyTimezone' => ['required', 'timezone'],
        ]);

        $family = $createFamily->execute($this->user(), [
            'name' => $validated['newFamilyName'],
            'timezone' => $validated['newFamilyTimezone'],
        ]);

        $this->reset('newFamilyName');
        $this->newFamilyTimezone = config('app.timezone');
        $this->setActiveFamily($family);
        unset($this->families);

        Flux::toast(variant: 'success', text: __('Family created.'));
    }

    public function switchFamily(int $familyId, SwitchActiveFamily $switchActiveFamily): void
    {
        $family = Family::query()->findOrFail($familyId);

        $switchActiveFamily->execute($this->user(), $family);
        $this->setActiveFamily($family);

        Flux::toast(variant: 'success', text: __('Active family changed.'));
    }

    public function updateFamily(UpdateFamily $updateFamily): void
    {
        $family = Family::query()->findOrFail($this->activeFamilyId);

        $validated = $this->validate([
            'familyName' => ['required', 'string', 'max:100'],
            'familyTimezone' => ['required', 'timezone'],
        ]);

        $updateFamily->execute($this->user(), $family, [
            'name' => $validated['familyName'],
            'timezone' => $validated['familyTimezone'],
        ]);

        unset($this->families, $this->activeFamily);

        Flux::toast(variant: 'success', text: __('Family settings updated.'));
    }

    /**
     * @return Collection<int, Family>
     */
    #[Computed]
    public function families(): Collection
    {
        return $this->user()->families()
            ->with('head')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function activeFamily(): ?Family
    {
        if (is_null($this->activeFamilyId)) {
            return null;
        }

        return $this->user()->families()
            ->with('head')
            ->find($this->activeFamilyId);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return timezone_identifiers_list();
    }

    private function setActiveFamily(?Family $family): void
    {
        $this->activeFamilyId = $family?->id;
        $this->familyName = $family?->name ?? '';
        $this->familyTimezone = $family?->timezone ?? config('app.timezone');

        unset($this->activeFamily);
    }

    private function user(): User
    {
        return Auth::user();
    }
};
?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-8">
        <div>
            <flux:heading size="xl">{{ __('Families') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Create a family or choose the shared workspace you want to use.') }}</flux:text>
        </div>

        <section class="grid gap-4 md:grid-cols-2">
            @forelse ($this->families as $family)
                <flux:card wire:key="family-{{ $family->id }}" class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <flux:heading class="truncate">{{ $family->name }}</flux:heading>
                            <flux:text class="truncate">{{ $family->timezone }}</flux:text>
                        </div>

                        @if ($family->id === $activeFamilyId)
                            <flux:badge color="green">{{ __('Active') }}</flux:badge>
                        @elseif ($family->isHead(auth()->user()))
                            <flux:badge>{{ __('Head') }}</flux:badge>
                        @endif
                    </div>

                    <flux:text>{{ __('Head: :name', ['name' => $family->head->name]) }}</flux:text>

                    @if ($family->id !== $activeFamilyId)
                        <flux:button wire:click="switchFamily({{ $family->id }})" wire:loading.attr="disabled">
                            {{ __('Use this family') }}
                        </flux:button>
                    @endif
                </flux:card>
            @empty
                <flux:callout icon="user-group" heading="{{ __('Create your first family') }}">
                    {{ __('Families keep dishes, plans, and grocery lists private to their members.') }}
                </flux:callout>
            @endforelse
        </section>

        <div class="grid gap-8 lg:grid-cols-2">
            <flux:card>
                <form wire:submit="createFamily" class="flex flex-col gap-6">
                    <div>
                        <flux:heading>{{ __('Create a family') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('You will become the Head of this family.') }}</flux:text>
                    </div>

                    <flux:input wire:model="newFamilyName" :label="__('Family name')" required />

                    <flux:select wire:model="newFamilyTimezone" :label="__('Time zone')" required>
                        @foreach ($this->timezones as $timezone)
                            <flux:select.option wire:key="new-timezone-{{ $timezone }}" :value="$timezone">
                                {{ $timezone }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled">
                        {{ __('Create family') }}
                    </flux:button>
                </form>
            </flux:card>

            @if ($this->activeFamily)
                <flux:card>
                    @if (auth()->user()->can('update', $this->activeFamily))
                        <form wire:submit="updateFamily" class="flex flex-col gap-6">
                            <div>
                                <flux:heading>{{ __('Family settings') }}</flux:heading>
                                <flux:text class="mt-1">{{ __('Only the Head can change these settings.') }}</flux:text>
                            </div>

                            <flux:input wire:model="familyName" :label="__('Family name')" required />

                            <flux:select wire:model="familyTimezone" :label="__('Time zone')" required>
                                @foreach ($this->timezones as $timezone)
                                    <flux:select.option wire:key="family-timezone-{{ $timezone }}" :value="$timezone">
                                        {{ $timezone }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:button variant="primary" type="submit" wire:loading.attr="disabled">
                                {{ __('Save settings') }}
                            </flux:button>
                        </form>
                    @else
                        <div class="flex flex-col gap-2">
                            <flux:heading>{{ $this->activeFamily->name }}</flux:heading>
                            <flux:text>{{ __('Time zone: :timezone', ['timezone' => $this->activeFamily->timezone]) }}</flux:text>
                            <flux:text>{{ __('Only the Head can change family settings.') }}</flux:text>
                        </div>
                    @endif
                </flux:card>
            @endif
        </div>
</div>
