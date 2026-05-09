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

            @can('users.view')
                <flux:navlist.item icon="users" :href="route('users.index')" wire:navigate :current="request()->routeIs('users.*')">
                    {{ __('Gebruikers') }}
                </flux:navlist.item>
            @endcan

            @can('roles.view')
                <flux:navlist.item icon="shield-check" :href="route('roles.index')" wire:navigate :current="request()->routeIs('roles.*')">
                    {{ __('Rollen') }}
                </flux:navlist.item>
            @endcan

            @can('activity.view')
                <flux:navlist.item icon="clipboard-document-list" :href="route('activity.index')" wire:navigate :current="request()->routeIs('activity.*')">
                    {{ __('Activiteit') }}
                </flux:navlist.item>
            @endcan

            @can('organisations.manage')
                <flux:navlist.item icon="building-office-2" :href="route('organisations.index')" wire:navigate :current="request()->routeIs('organisations.*')">
                    {{ __('Organisaties') }}
                </flux:navlist.item>
            @endcan
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
        @if (auth()->check() && auth()->user()->isImpersonated())
            <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
                <div class="flex items-center justify-between gap-4">
                    <span>{{ __('Je impersoneert :email.', ['email' => auth()->user()->email]) }}</span>
                    <form method="POST" action="{{ route('impersonate.stop') }}">
                        @csrf
                        <flux:button type="submit" size="sm" variant="ghost">{{ __('Stop impersonatie') }}</flux:button>
                    </form>
                </div>
            </flux:callout>
        @endif
        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" class="mb-6">{{ session('status') }}</flux:callout>
        @endif
        @if (session('error'))
            <flux:callout variant="danger" icon="x-circle" class="mb-6">{{ session('error') }}</flux:callout>
        @endif
        {{ $slot }}
    </flux:main>
    @fluxScripts
</body>
</html>
