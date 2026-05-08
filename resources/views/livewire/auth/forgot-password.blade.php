<div>
    <flux:heading size="lg">{{ __('Wachtwoord vergeten') }}</flux:heading>
    <flux:text class="mb-6 mt-2 text-zinc-500 dark:text-zinc-400">
        {{ __('Vul je e-mailadres in. We sturen je een link om je wachtwoord te herstellen.') }}
    </flux:text>

    @if ($status)
        <flux:callout variant="success" icon="check-circle" class="mb-4">{{ $status }}</flux:callout>
    @endif

    <form wire:submit="submit" class="space-y-4">
        <flux:input
            wire:model="email"
            label="{{ __('E-mailadres') }}"
            type="email"
            name="email"
            autocomplete="email"
            required
            autofocus />

        <flux:button type="submit" variant="primary" class="w-full">
            {{ __('Verstuur herstellink') }}
        </flux:button>

        <flux:text class="text-center">
            <flux:link href="{{ route('login') }}" wire:navigate>{{ __('Terug naar inloggen') }}</flux:link>
        </flux:text>
    </form>
</div>
