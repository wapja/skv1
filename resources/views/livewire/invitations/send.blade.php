<div>
    <flux:button variant="primary" icon="paper-airplane" wire:click="$set('open', true)">
        {{ __('Gebruiker uitnodigen') }}
    </flux:button>

    <flux:modal wire:model.self="open" class="md:w-96">
        <form wire:submit="send" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Gebruiker uitnodigen') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400">
                    {{ __('De ontvanger krijgt een e-mail met een activatielink.') }}
                </flux:text>
            </div>

            <flux:input
                wire:model="firstName"
                label="{{ __('Voornaam') }}"
                required
                autofocus />

            <flux:input
                wire:model="middleName"
                label="{{ __('Tussenvoegsel') }}" />

            <flux:input
                wire:model="lastName"
                label="{{ __('Achternaam') }}"
                required />

            <flux:input
                wire:model="email"
                label="{{ __('E-mailadres') }}"
                type="email"
                required />

            <flux:select wire:model="locale" label="{{ __('Taal') }}">
                <option value="nl">{{ __('Nederlands') }}</option>
                <option value="en">{{ __('Engels') }}</option>
            </flux:select>

            <flux:checkbox.group wire:model="roles" label="{{ __('Rollen') }}">
                @foreach ($this->availableRoles() as $roleName => $roleLabel)
                    <flux:checkbox value="{{ $roleName }}" label="{{ $roleLabel }}" />
                @endforeach
            </flux:checkbox.group>

            @if (count($this->availableOrganisations()) > 0)
                <flux:select wire:model="organisationId" label="{{ __('Organisatie') }}" required>
                    <option value="">{{ __('Kies een organisatie') }}</option>
                    @foreach ($this->availableOrganisations() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('open', false)">
                    {{ __('Annuleren') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Versturen') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
