<x-layouts::auth :title="__('Confirm your identity')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirm your identity')"
            :description="__('This is a secure area of the application. Please confirm your identity before continuing.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />
        <flux:error name="workos" />

        @if (auth()->user()->workos_user_id)
            <form method="POST" action="{{ route('workos.confirm') }}">
                @csrf
                <flux:button type="submit" variant="outline" class="w-full" data-test="confirm-workos-button">
                    {{ __('Confirm with Google, GitHub, or Apple') }}
                </flux:button>
            </form>

            <div class="flex items-center gap-3" aria-hidden="true">
                <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
                <span class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    {{ __('or use another method') }}
                </span>
                <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
            </div>
        @endif

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with password')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('Confirm') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
