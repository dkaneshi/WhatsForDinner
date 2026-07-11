<div class="flex flex-col gap-4">
    <div class="flex items-center gap-3" aria-hidden="true">
        <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
        <span class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            {{ __('or') }}
        </span>
        <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
    </div>

    <flux:button
        :href="route('workos.redirect')"
        variant="outline"
        class="w-full"
        data-test="workos-auth-button"
    >
        {{ __('Continue with Google or GitHub') }}
    </flux:button>

    <flux:error name="workos" />
</div>
