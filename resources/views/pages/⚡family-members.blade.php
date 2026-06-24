<?php

use App\Actions\Families\AcceptFamilyHeadship;
use App\Actions\Families\CancelFamilyHeadship;
use App\Actions\Families\LeaveFamily;
use App\Actions\Families\OfferFamilyHeadship;
use App\Actions\Families\RemoveFamilyMember;
use App\Actions\Families\ResolveActiveFamily;
use App\Models\Family;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Family members')] class extends Component {
    #[Locked]
    public ?int $activeFamilyId = null;

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

    public function removeMember(int $memberId, RemoveFamilyMember $removeFamilyMember): void
    {
        $member = User::query()->findOrFail($memberId);

        $removeFamilyMember->execute($this->user(), $this->activeFamily(), $member);
        unset($this->family);

        Flux::toast(variant: 'success', text: __('Member removed.'));
    }

    public function offerHeadship(int $memberId, OfferFamilyHeadship $offerFamilyHeadship): void
    {
        $member = User::query()->findOrFail($memberId);

        $offerFamilyHeadship->execute($this->user(), $this->activeFamily(), $member);
        unset($this->family);

        Flux::toast(variant: 'success', text: __('Leadership offer sent.'));
    }

    public function acceptHeadship(AcceptFamilyHeadship $acceptFamilyHeadship): void
    {
        $acceptFamilyHeadship->execute($this->user(), $this->activeFamily());
        unset($this->family);

        Flux::toast(variant: 'success', text: __('You are now the Head of this family.'));
    }

    public function cancelHeadship(CancelFamilyHeadship $cancelFamilyHeadship): void
    {
        $cancelFamilyHeadship->execute($this->user(), $this->activeFamily());
        unset($this->family);

        Flux::toast(variant: 'success', text: __('Leadership offer canceled.'));
    }

    public function leaveFamily(LeaveFamily $leaveFamily): void
    {
        $leaveFamily->execute($this->user(), $this->activeFamily());

        $this->redirectRoute('families.index', navigate: true);
    }

    #[Computed]
    public function family(): Family
    {
        return $this->user()->families()
            ->with(['head', 'pendingHead', 'members' => fn ($query) => $query->orderBy('name')])
            ->findOrFail($this->activeFamilyId);
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

<div class="mx-auto flex w-full max-w-3xl flex-col gap-8">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('families.index')" wire:navigate>{{ __('Families') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Members') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-4">{{ __('Family members') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Manage who belongs to :family and who serves as Head.', ['family' => $this->family->name]) }}</flux:text>
    </div>

    <flux:error name="family" />
    <flux:error name="member" />

    @if ($this->family->pendingHead)
        <flux:callout icon="arrow-right" heading="{{ __('Leadership offer pending') }}">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <span>{{ __(':name has been offered the Head role. The current Head remains in charge until it is accepted.', ['name' => $this->family->pendingHead->name]) }}</span>

                <div class="flex flex-wrap gap-2">
                    @if ($this->family->pending_head_user_id === auth()->id())
                        <flux:button variant="primary" size="sm" wire:click="acceptHeadship" wire:loading.attr="disabled">
                            {{ __('Accept') }}
                        </flux:button>
                    @endif

                    @if ($this->family->isHead(auth()->user()))
                        <flux:button size="sm" wire:click="cancelHeadship" wire:loading.attr="disabled">
                            {{ __('Cancel offer') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        </flux:callout>
    @endif

    <section class="flex flex-col gap-4">
        @foreach ($this->family->members as $member)
            <flux:card wire:key="member-{{ $member->id }}" class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading class="truncate">{{ $member->name }}</flux:heading>

                        @if ($this->family->isHead($member))
                            <flux:badge color="amber">{{ __('Head') }}</flux:badge>
                        @endif

                        @if ($member->is(auth()->user()))
                            <flux:badge>{{ __('You') }}</flux:badge>
                        @endif
                    </div>
                    <flux:text class="truncate">{{ $member->email }}</flux:text>
                </div>

                @if ($this->family->isHead(auth()->user()) && ! $this->family->isHead($member))
                    <div class="flex flex-wrap gap-2">
                        @if ($this->family->pending_head_user_id !== $member->id)
                            <flux:button size="sm" wire:click="offerHeadship({{ $member->id }})" wire:loading.attr="disabled">
                                {{ __('Offer Head role') }}
                            </flux:button>
                        @endif

                        <flux:button
                            size="sm"
                            variant="danger"
                            wire:click="removeMember({{ $member->id }})"
                            wire:confirm="{{ __('Remove :name from this family?', ['name' => $member->name]) }}"
                            wire:loading.attr="disabled"
                        >
                            {{ __('Remove') }}
                        </flux:button>
                    </div>
                @endif
            </flux:card>
        @endforeach
    </section>

    @unless ($this->family->isHead(auth()->user()))
        <flux:card class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading>{{ __('Leave family') }}</flux:heading>
                <flux:text>{{ __('You will immediately lose access to this family’s plans and dishes.') }}</flux:text>
            </div>

            <flux:button
                variant="danger"
                wire:click="leaveFamily"
                wire:confirm="{{ __('Leave this family?') }}"
                wire:loading.attr="disabled"
            >
                {{ __('Leave family') }}
            </flux:button>
        </flux:card>
    @endunless
</div>
