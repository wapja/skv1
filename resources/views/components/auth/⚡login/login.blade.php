<div>
    <flux:heading size="lg">{{ __('Inloggen') }}</flux:heading>
    <flux:text class="mb-6 mt-2 text-zinc-500 dark:text-zinc-400">
        {{ __('Voer je e-mailadres en wachtwoord in om door te gaan.') }}
    </flux:text>

    <form wire:submit="submit" class="space-y-4">
        <flux:input
            wire:model="email"
            label="{{ __('E-mailadres') }}"
            type="email"
            name="email"
            autocomplete="email"
            required
            autofocus />

        <flux:input
            wire:model="password"
            label="{{ __('Wachtwoord') }}"
            type="password"
            name="password"
            autocomplete="current-password"
            required />

        <div class="flex items-center justify-between">
            <flux:checkbox wire:model="remember" label="{{ __('Onthoud mij') }}" />
            <flux:link href="{{ route('password.request') }}" wire:navigate>
                {{ __('Wachtwoord vergeten?') }}
            </flux:link>
        </div>

        <flux:button type="submit" variant="primary" class="w-full">
            {{ __('Inloggen') }}
        </flux:button>
    </form>
</div>
