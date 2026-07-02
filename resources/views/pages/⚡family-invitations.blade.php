<?php

use App\Actions\Families\ResendFamilyInvitation;
use App\Actions\Families\ResolveActiveFamily;
use App\Actions\Families\RevokeFamilyInvitation;
use App\Actions\Families\SendFamilyInvitation;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Family invitations')] class extends Component {
    public string $email = '';

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

        Gate::authorize('inviteMembers', $family);

        $this->activeFamilyId = $family->id;
    }

    public function sendInvitation(SendFamilyInvitation $sendFamilyInvitation): void
    {
        $this->email = trim($this->email);

        $validated = $this->validate([
            'email' => ['required', Rule::email(), 'max:255'],
        ]);

        $sendFamilyInvitation->execute($this->user(), $this->activeFamily(), $validated['email']);

        $this->reset('email');
        unset($this->invitations);

        Flux::toast(variant: 'success', text: __('Invitation sent.'));
    }

    public function resendInvitation(int $invitationId, ResendFamilyInvitation $resendFamilyInvitation): void
    {
        $invitation = FamilyInvitation::query()->findOrFail($invitationId);

        $resendFamilyInvitation->execute($this->user(), $invitation);
        unset($this->invitations);

        Flux::toast(variant: 'success', text: __('Invitation resent.'));
    }

    public function revokeInvitation(int $invitationId, RevokeFamilyInvitation $revokeFamilyInvitation): void
    {
        $invitation = FamilyInvitation::query()->findOrFail($invitationId);

        $revokeFamilyInvitation->execute($this->user(), $invitation);
        unset($this->invitations);

        Flux::toast(variant: 'success', text: __('Invitation revoked.'));
    }

    /**
     * @return Collection<int, FamilyInvitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return $this->activeFamily()->invitations()
            ->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get();
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

<div class="mx-auto flex w-full max-w-3xl flex-col gap-8">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('families.index')" wire:navigate>{{ __('Families') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Invitations') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-4">{{ __('Family invitations') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Invite people to join :family.', ['family' => $this->activeFamily()->name]) }}</flux:text>
    </div>

    <flux:card>
        <form wire:submit="sendInvitation" class="flex flex-col gap-5 sm:flex-row sm:items-end">
            <div class="flex-1">
                <flux:input wire:model="email" :label="__('Email address')" type="email" required />
            </div>

            <flux:button variant="primary" type="submit" icon="paper-airplane" wire:loading.attr="disabled">
                {{ __('Send invitation') }}
            </flux:button>
        </form>
    </flux:card>

    <section class="flex flex-col gap-4">
        <div>
            <flux:heading>{{ __('Pending invitations') }}</flux:heading>
            <flux:text>{{ __('Invitations expire seven days after they are sent.') }}</flux:text>
        </div>

        @forelse ($this->invitations as $invitation)
            <flux:card wire:key="invitation-{{ $invitation->id }}" class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <flux:heading class="truncate">{{ $invitation->email }}</flux:heading>
                    <flux:text>{{ __('Expires :time', ['time' => $invitation->expires_at->diffForHumans()]) }}</flux:text>
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" wire:click="resendInvitation({{ $invitation->id }})" wire:loading.attr="disabled">
                        {{ __('Resend') }}
                    </flux:button>
                    <flux:button size="sm" variant="danger" wire:click="revokeInvitation({{ $invitation->id }})" wire:loading.attr="disabled">
                        {{ __('Revoke') }}
                    </flux:button>
                </div>
            </flux:card>
        @empty
            <flux:callout icon="envelope" heading="{{ __('No pending invitations') }}">
                {{ __('Invite someone when you are ready to plan together.') }}
            </flux:callout>
        @endforelse
    </section>
</div>
