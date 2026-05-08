<x-layouts.guest title="404">
    <flux:heading size="lg">{{ __('Pagina niet gevonden') }}</flux:heading>
    <flux:text class="mt-2 mb-6 text-zinc-500 dark:text-zinc-400">
        {{ __('De pagina die je zoekt bestaat niet of is verplaatst.') }}
    </flux:text>
    @auth
        <flux:button :href="route('dashboard')" variant="primary" class="w-full">{{ __('Terug naar dashboard') }}</flux:button>
    @else
        <flux:button :href="route('login')" variant="primary" class="w-full">{{ __('Terug naar inloggen') }}</flux:button>
    @endauth
</x-layouts.guest>
