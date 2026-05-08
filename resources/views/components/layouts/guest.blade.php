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
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
        <div class="mb-8 text-center">
            <flux:heading size="xl" class="font-semibold">{{ config('app.name') }}</flux:heading>
            @isset($subtitle)
                <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400">{{ $subtitle }}</flux:text>
            @endisset
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            {{ $slot }}
        </div>
    </main>
    @fluxScripts
</body>
</html>
