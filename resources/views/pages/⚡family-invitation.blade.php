<?php

use App\Actions\Families\AcceptFamilyInvitation;
use App\Actions\Families\DeclineFamilyInvitation;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Family invitation')] class extends Component {
    #[Locked]
    public string $token;

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function acceptInvitation(AcceptFamilyInvitation $acceptFamilyInvitation): void
    {
        $acceptFamilyInvitation->execute($this->user(), $this->pendingInvitation());

        $this->redirectRoute('families.index', navigate: true);
    }

    public function declineInvitation(DeclineFamilyInvitation $declineFamilyInvitation): void
    {
        $declineFamilyInvitation->execute($this->user(), $this->pendingInvitation());

        $this->redirectRoute('dashboard', navigate: true);
    }

    #[Computed]
    public function invitation(): ?FamilyInvitation
    {
        return FamilyInvitation::findForToken($this->token)?->load(['family', 'inviter']);
    }

    private function pendingInvitation(): FamilyInvitation
    {
        $invitation = $this->invitation;

        if (! $invitation || ! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation is no longer available.'),
            ]);
        }

        return $invitation;
    }

    private function user(): User
    {
        return Auth::user();
    }
};
?>

<div class="mx-auto flex w-full max-w-xl flex-col gap-6">
    @if (! $this->invitation)
        <flux:callout variant="danger" icon="exclamation-triangle" heading="{{ __('Invitation unavailable') }}">
            {{ __('This invitation link is invalid or has been replaced.') }}
        </flux:callout>
    @elseif (! $this->invitation->isPending())
        <flux:callout variant="warning" icon="clock" heading="{{ __('Invitation unavailable') }}">
            {{ __('This invitation has expired or has already been answered.') }}
        </flux:callout>
    @elseif (! Gate::allows('respond', $this->invitation))
        <flux:callout variant="warning" icon="exclamation-triangle" heading="{{ __('Different email required') }}">
            {{ __('Sign in as :email to respond to this invitation.', ['email' => $this->invitation->email]) }}
        </flux:callout>
    @else
        <flux:card class="flex flex-col gap-6 text-center">
            <div class="flex flex-col gap-2">
                <flux:badge color="amber" class="mx-auto">{{ __('Family invitation') }}</flux:badge>
                <flux:heading size="xl">{{ __('Join :family?', ['family' => $this->invitation->family->name]) }}</flux:heading>
                <flux:text>
                    {{ __(':name invited you to share dinner plans and grocery lists.', ['name' => $this->invitation->inviter->name]) }}
                </flux:text>
            </div>

            <flux:error name="invitation" />

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-center">
                <flux:button wire:click="declineInvitation" wire:loading.attr="disabled">
                    {{ __('Decline') }}
                </flux:button>
                <flux:button variant="primary" wire:click="acceptInvitation" wire:loading.attr="disabled">
                    {{ __('Accept invitation') }}
                </flux:button>
            </div>
        </flux:card>
    @endif
</div>
