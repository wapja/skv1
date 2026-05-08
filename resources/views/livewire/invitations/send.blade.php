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
                wire:model="email"
                label="{{ __('E-mailadres') }}"
                type="email"
                required
                autofocus />

            <flux:select wire:model="locale" label="{{ __('Taal') }}">
                <option value="nl">{{ __('Nederlands') }}</option>
                <option value="en">{{ __('Engels') }}</option>
            </flux:select>

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
