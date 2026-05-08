<x-layouts.app :title="__('Dashboard')">
    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
    <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400">
        @if (tenant())
            {{ __('Welkom in de werkplek van :org.', ['org' => tenant()->name]) }}
        @else
            {{ __('Welkom.') }}
        @endif
    </flux:text>

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <flux:heading size="md">{{ __('Aangemeld als') }}</flux:heading>
            <flux:text class="mt-1">{{ auth()->user()->name }}</flux:text>
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ auth()->user()->email }}</flux:text>
        </div>

        @if (auth()->user()->isSuperAdmin())
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950">
                <flux:heading size="md">{{ __('Super-admin') }}</flux:heading>
                <flux:text class="mt-1 text-amber-800 dark:text-amber-200">
                    {{ __('Je hebt systeembrede rechten in alle organisaties.') }}
                </flux:text>
            </div>
        @endif
    </div>
</x-layouts.app>
