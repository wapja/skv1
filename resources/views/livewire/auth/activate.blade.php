<div>
    <flux:heading size="lg">{{ __('Account activeren') }}</flux:heading>
    <flux:text class="mb-6 mt-2 text-zinc-500 dark:text-zinc-400">
        {{ __('Kies een wachtwoord voor :email om je account te activeren.', ['email' => $email]) }}
    </flux:text>

    @error('token')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4">{{ $message }}</flux:callout>
    @enderror

    <form wire:submit="submit" class="space-y-4">
        <flux:input
            label="{{ __('E-mailadres') }}"
            type="email"
            value="{{ $email }}"
            disabled />

        <flux:input
            wire:model="password"
            label="{{ __('Nieuw wachtwoord') }}"
            type="password"
            name="password"
            autocomplete="new-password"
            required
            autofocus />

        <flux:input
            wire:model="password_confirmation"
            label="{{ __('Bevestig wachtwoord') }}"
            type="password"
            name="password_confirmation"
            autocomplete="new-password"
            required />

        <flux:button type="submit" variant="primary" class="w-full">
            {{ __('Account activeren') }}
        </flux:button>
    </form>
</div>
