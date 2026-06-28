<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('Welcome')])
    </head>
    <body class="min-h-screen bg-cream-50 text-cocoa-900 antialiased dark:bg-cocoa-900 dark:text-cream-50">
        <main>
            <section class="relative isolate overflow-hidden border-b border-cream-100 bg-cream-50 dark:border-cocoa-800 dark:bg-cocoa-900">
                <div class="absolute inset-x-0 bottom-0 h-48 bg-olive-700 dark:bg-cocoa-800" aria-hidden="true"></div>

                <div class="relative mx-auto flex min-h-[82svh] w-full max-w-7xl flex-col gap-10 px-6 py-6 sm:px-8 lg:px-10">
                    <header class="flex items-center justify-between gap-4">
                        <a href="{{ route('home') }}" class="flex items-center gap-3 font-semibold">
                            <span class="flex aspect-square size-10 items-center justify-center rounded-md bg-accent text-accent-foreground">
                                <x-app-logo-icon class="size-6" />
                            </span>
                            <span>{{ config('app.product_name') }}</span>
                        </a>

                        <nav class="flex items-center gap-3 text-sm font-medium">
                            @if (Route::has('login'))
                                <a href="{{ route('login') }}" class="rounded-md px-3 py-2 text-cocoa-800 transition hover:bg-cream-100 dark:text-cream-100 dark:hover:bg-cocoa-800">
                                    {{ __('Log in') }}
                                </a>
                            @endif

                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="rounded-md bg-accent px-4 py-2 text-accent-foreground shadow-sm transition hover:bg-terracotta-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent dark:hover:bg-harvest-500">
                                    {{ __('Register') }}
                                </a>
                            @endif
                        </nav>
                    </header>

                    <div class="grid grow items-center gap-10 pb-8 lg:grid-cols-[minmax(0,1fr)_minmax(24rem,34rem)]">
                        <div class="max-w-3xl">
                            <p class="text-sm font-semibold uppercase text-terracotta-600 dark:text-harvest-500">
                                {{ __('Dinner planning for real households') }}
                            </p>

                            <h1 class="mt-5 max-w-4xl text-4xl font-bold leading-tight sm:text-5xl lg:text-6xl">
                                {{ __('Plan dinners, organize dishes, and build the grocery list without the weeknight scramble.') }}
                            </h1>

                            <p class="mt-6 max-w-2xl text-lg leading-8 text-cocoa-800 dark:text-cream-100">
                                {{ __("What's for Dinner? helps families choose weekday meals, keep favorite dishes handy, track alternatives, and turn the week's plan into a focused grocery list.") }}
                            </p>

                            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-md bg-accent px-5 py-3 text-sm font-semibold text-accent-foreground shadow-sm transition hover:bg-terracotta-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent dark:hover:bg-harvest-500">
                                        {{ __('Create your dinner plan') }}
                                    </a>
                                @endif

                                @if (Route::has('login'))
                                    <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-md border border-cream-100 bg-white/80 px-5 py-3 text-sm font-semibold text-cocoa-800 transition hover:bg-white dark:border-cocoa-700 dark:bg-cocoa-800/80 dark:text-cream-50 dark:hover:bg-cocoa-800">
                                        {{ __('Sign in') }}
                                    </a>
                                @endif
                            </div>
                        </div>

                        <div class="rounded-lg border border-cream-100 bg-white/90 p-4 shadow-xl shadow-cocoa-900/10 dark:border-cocoa-700 dark:bg-cocoa-800/95 dark:shadow-black/30">
                            <div class="flex items-center justify-between gap-4 border-b border-cream-100 pb-4 dark:border-cocoa-700">
                                <div>
                                    <p class="text-sm font-semibold">{{ __('This week') }}</p>
                                    <p class="text-sm text-cocoa-800 dark:text-cream-100">{{ __('Dinner plan preview') }}</p>
                                </div>
                                <span class="rounded-md bg-olive-600 px-3 py-1 text-xs font-semibold text-white dark:bg-harvest-500 dark:text-cocoa-900">
                                    {{ __('Ready') }}
                                </span>
                            </div>

                            <div class="mt-4 grid gap-3">
                                @foreach ([__('Mon') => __('Lemon chicken bowls'), __('Tue') => __('Taco night'), __('Wed') => __('Pasta and salad'), __('Thu') => __('Teriyaki tofu'), __('Fri') => __('Pizza leftovers')] as $day => $meal)
                                    <div class="grid grid-cols-[3rem_minmax(0,1fr)] items-center gap-3 rounded-lg border border-cream-100 bg-cream-50 p-3 dark:border-cocoa-700 dark:bg-cocoa-900/60">
                                        <span class="text-sm font-semibold text-terracotta-600 dark:text-harvest-500">{{ $day }}</span>
                                        <span class="truncate text-sm">{{ $meal }}</span>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-3">
                                <div class="rounded-lg bg-olive-600 p-3 text-white dark:bg-olive-700">
                                    <p class="text-xs font-medium uppercase">{{ __('Groceries') }}</p>
                                    <p class="mt-1 text-2xl font-bold">18</p>
                                </div>
                                <div class="rounded-lg bg-harvest-500 p-3 text-cocoa-900">
                                    <p class="text-xs font-medium uppercase">{{ __('Dishes') }}</p>
                                    <p class="mt-1 text-2xl font-bold">42</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bg-olive-700 px-6 py-12 text-cream-50 dark:bg-cocoa-800 sm:px-8 lg:px-10">
                <div class="mx-auto grid max-w-7xl gap-4 md:grid-cols-3">
                    <article class="rounded-lg border border-cream-50/15 p-5">
                        <h2 class="font-semibold">{{ __('Plan the week') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-cream-100">{{ __('Pick weekday dinners, add alternatives, and keep the whole household aligned.') }}</p>
                    </article>

                    <article class="rounded-lg border border-cream-50/15 p-5">
                        <h2 class="font-semibold">{{ __('Reuse family favorites') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-cream-100">{{ __('Build a dish collection with proteins, ingredients, and meals everyone already likes.') }}</p>
                    </article>

                    <article class="rounded-lg border border-cream-50/15 p-5">
                        <h2 class="font-semibold">{{ __('Shop from the plan') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-cream-100">{{ __('Turn planned dinners into a grocery list that is easier to check off at the store.') }}</p>
                    </article>
                </div>
            </section>
        </main>
    </body>
</html>
