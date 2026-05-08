@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" x-data="{ dark: $persist(false).as('skv1-theme') }" :class="{ 'dark': dark }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' — '.config('app.name') : config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-full bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <flux:sidebar.header>
            <flux:brand href="{{ route('dashboard') }}" name="{{ config('app.name') }}" />
        </flux:sidebar.header>

        <flux:navlist variant="outline">
            <flux:navlist.item icon="home" :href="route('dashboard')" wire:navigate :current="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        @auth
            <flux:dropdown class="mt-auto" align="end">
                <flux:profile :name="auth()->user()->name" :initials="strtoupper(substr(auth()->user()->name, 0, 1))" />
                <flux:menu>
                    <flux:menu.item icon="moon" x-on:click="dark = !dark">{{ __('Donker thema schakelen') }}</flux:menu.item>
                    <flux:menu.separator />
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle">
                            {{ __('Uitloggen') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        @endauth
    </flux:sidebar>

    <flux:main>
        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" class="mb-6">{{ session('status') }}</flux:callout>
        @endif
        {{ $slot }}
    </flux:main>
    @fluxScripts
</body>
</html>
