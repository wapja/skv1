<x-layouts.guest title="500">
    <flux:heading size="lg">{{ __('Er ging iets mis') }}</flux:heading>
    <flux:text class="mt-2 mb-6 text-zinc-500 dark:text-zinc-400">
        {{ __('Er ging iets mis aan onze kant. We zijn ervan op de hoogte gesteld.') }}
    </flux:text>
    <flux:button :href="url('/')" variant="primary" class="w-full">{{ __('Terug naar de startpagina') }}</flux:button>
</x-layouts.guest>
