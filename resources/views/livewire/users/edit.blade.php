<div class="max-w-xl space-y-6">
    <flux:heading size="xl">{{ __('Gebruiker bewerken') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <flux:input wire:model="name" label="{{ __('Naam') }}" required />
        <flux:input wire:model="email" type="email" label="{{ __('E-mailadres') }}" required />

        <flux:select wire:model="status" label="{{ __('Status') }}">
            <option value="active">{{ __('Actief') }}</option>
            <option value="pending_activation">{{ __('Wachtend op activering') }}</option>
            <option value="disabled">{{ __('Uitgeschakeld') }}</option>
        </flux:select>

        <flux:select wire:model="locale" label="{{ __('Taal') }}">
            <option value="nl">{{ __('Nederlands') }}</option>
            <option value="en">{{ __('Engels') }}</option>
        </flux:select>

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" :href="route('users.index')" wire:navigate>
                {{ __('Annuleren') }}
            </flux:button>
            <flux:button type="submit" variant="primary">{{ __('Opslaan') }}</flux:button>
        </div>
    </form>
</div>
