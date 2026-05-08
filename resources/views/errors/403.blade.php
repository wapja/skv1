<x-layouts.guest title="403">
    <flux:heading size="lg">{{ __('Geen toegang') }}</flux:heading>
    <flux:text class="mt-2 mb-6 text-zinc-500 dark:text-zinc-400">
        {{ __('Je hebt geen toestemming om deze pagina te bekijken. Log opnieuw in om door te gaan.') }}
    </flux:text>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <flux:button type="submit" variant="primary" class="w-full">{{ __('Uitloggen en opnieuw inloggen') }}</flux:button>
    </form>
</x-layouts.guest>
