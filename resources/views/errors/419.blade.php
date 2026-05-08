<x-layouts.guest title="419">
    <flux:heading size="lg">{{ __('Sessie verlopen') }}</flux:heading>
    <flux:text class="mt-2 mb-6 text-zinc-500 dark:text-zinc-400">
        {{ __('Je sessie is verlopen of het formulier is te oud. Probeer het opnieuw.') }}
    </flux:text>
    <flux:button :href="url()->previous()" variant="primary" class="w-full">{{ __('Terug') }}</flux:button>
</x-layouts.guest>
