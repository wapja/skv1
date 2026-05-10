<div>
    <flux:heading size="lg">{{ __('Nieuw wachtwoord instellen') }}</flux:heading>
    <flux:text class="mb-6 mt-2 text-zinc-500 dark:text-zinc-400">
        {{ __('Kies een nieuw wachtwoord van minimaal 8 tekens.') }}
    </flux:text>

    <form wire:submit="submit" class="space-y-4">
        <flux:input
            wire:model="email"
            label="{{ __('E-mailadres') }}"
            type="email"
            name="email"
            autocomplete="email"
            required />

        <flux:input
            wire:model="password"
            label="{{ __('Nieuw wachtwoord') }}"
            type="password"
            name="password"
            autocomplete="new-password"
            required />

        <flux:input
            wire:model="password_confirmation"
            label="{{ __('Bevestig wachtwoord') }}"
            type="password"
            name="password_confirmation"
            autocomplete="new-password"
            required />

        <flux:button type="submit" variant="primary" class="w-full">
            {{ __('Wachtwoord opslaan') }}
        </flux:button>
    </form>
</div>
