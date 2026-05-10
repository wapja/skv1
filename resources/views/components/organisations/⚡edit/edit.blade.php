<div class="max-w-xl space-y-6">
    <flux:heading size="xl">
        {{ $organisation ? __('Organisatie bewerken') : __('Nieuwe organisatie') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-4">
        <flux:input wire:model="name" label="{{ __('Naam') }}" required />
        <flux:input wire:model="slug" label="{{ __('Slug') }}" required />
        <flux:textarea wire:model="description" label="{{ __('Beschrijving') }}" rows="3" />

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" :href="route('organisations.index')" wire:navigate>
                {{ __('Annuleren') }}
            </flux:button>
            <flux:button type="submit" variant="primary">{{ __('Opslaan') }}</flux:button>
        </div>
    </form>
</div>
