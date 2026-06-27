<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-cream-100 bg-cream-100 dark:border-cocoa-800 dark:bg-cocoa-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate aria-label="{{ __('Open dashboard') }}">
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="user-group" :href="route('families.index')" :current="request()->routeIs('families.*')" wire:navigate aria-label="{{ __('Manage families') }}">
                        {{ __('Families') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="calendar-days" :href="route('weekly-plans.show')" :current="request()->routeIs('weekly-plans.*')" wire:navigate aria-label="{{ __('Open weekly plan') }}">
                        {{ __('Plan') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="shopping-cart" :href="route('grocery-lists.show')" :current="request()->routeIs('grocery-lists.*')" wire:navigate aria-label="{{ __('Open grocery list') }}">
                        {{ __('Groceries') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="list-bullet" :href="route('ingredients.index')" :current="request()->routeIs('ingredients.*')" wire:navigate aria-label="{{ __('Manage ingredients') }}">
                        {{ __('Ingredients') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="book-open" :href="route('dishes.index')" :current="request()->routeIs('dishes.*')" wire:navigate aria-label="{{ __('Manage dishes') }}">
                        {{ __('Dishes') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="arrow-up-tray" :href="route('dishes.import')" :current="request()->routeIs('dishes.import')" wire:navigate aria-label="{{ __('Import dishes from Markdown') }}">
                    {{ __('Import') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="cog-6-tooth" :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate aria-label="{{ __('Open settings') }}">
                    {{ __('Settings') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="border-b border-cream-100 bg-cream-50/95 lg:hidden dark:border-cocoa-800 dark:bg-cocoa-900/95">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" aria-label="{{ __('Open navigation') }}" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
