<x-layouts.guest title="429">
    <flux:heading size="lg">{{ __('Te veel verzoeken') }}</flux:heading>
    <flux:text class="mt-2 mb-6 text-zinc-500 dark:text-zinc-400">
        {{ __('Je hebt te snel te veel verzoeken gedaan. Wacht een moment en probeer het opnieuw.') }}
    </flux:text>
    @if (! empty($exception?->getHeaders()['Retry-After'] ?? null))
        <flux:text class="text-zinc-500 dark:text-zinc-400">
            {{ __('Probeer over :seconds seconden opnieuw.', ['seconds' => $exception->getHeaders()['Retry-After']]) }}
        </flux:text>
    @endif
</x-layouts.guest>
