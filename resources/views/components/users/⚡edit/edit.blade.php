<div class="max-w-2xl space-y-6">
    <flux:heading size="xl">{{ __('Gebruiker bewerken') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <div class="grid grid-cols-12 gap-4">
            <flux:input wire:model="first_name" label="{{ __('Voornaam') }}" required class="col-span-5" />
            <flux:input wire:model="middle_name" label="{{ __('Tussenvoegsel') }}" class="col-span-2" />
            <flux:input wire:model="last_name" label="{{ __('Achternaam') }}" required class="col-span-5" />
        </div>

        <flux:input wire:model="email" type="email" label="{{ __('E-mailadres') }}" required />

        <flux:input
            :value="$user->organisation?->name ?? '—'"
            label="{{ __('Organisatie') }}"
            readonly
            disabled />

        <div class="grid grid-cols-2 gap-4">
            <flux:input wire:model="internal_id" label="{{ __('Personeelsnummer') }}" />
            <flux:input wire:model="phone" label="{{ __('Telefoon') }}" />
        </div>

        <flux:input wire:model="address" label="{{ __('Adres') }}" />

        <div class="grid grid-cols-2 gap-4">
            <flux:input wire:model="start_date" type="date" label="{{ __('Indiensttredingsdatum') }}" required />
            <flux:input wire:model="end_date" type="date" label="{{ __('Uitdiensttredingsdatum') }}" />
        </div>

        <flux:select wire:model="status" label="{{ __('Status') }}">
            <option value="active">{{ __('Actief') }}</option>
            <option value="pending_activation">{{ __('Wachtend op activering') }}</option>
            <option value="disabled">{{ __('Uitgeschakeld') }}</option>
        </flux:select>

        <flux:select wire:model="locale" label="{{ __('Taal') }}">
            <option value="nl">{{ __('Nederlands') }}</option>
            <option value="en">{{ __('Engels') }}</option>
        </flux:select>

        <flux:checkbox.group wire:model="roles" label="{{ __('Rollen') }}">
            @foreach ($this->availableRoles() as $roleName => $roleLabel)
                <flux:checkbox value="{{ $roleName }}" label="{{ $roleLabel }}" />
            @endforeach
        </flux:checkbox.group>

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" :href="route('users.index')" wire:navigate>
                {{ __('Annuleren') }}
            </flux:button>
            <flux:button type="submit" variant="primary">{{ __('Opslaan') }}</flux:button>
        </div>
    </form>
</div>
