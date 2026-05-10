<div>
    <flux:modal wire:model.self="open" class="md:w-96">
        <form wire:submit="start" class="space-y-4">
            <flux:heading size="lg">{{ __('Impersoneren') }}</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400">
                {{ __('Geef een reden op voor deze impersonatie. De reden wordt gelogd.') }}
            </flux:text>

            <flux:textarea
                wire:model="reason"
                label="{{ __('Reden') }}"
                rows="3"
                required
                autofocus />

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('open', false)">
                    {{ __('Annuleren') }}
                </flux:button>
                <flux:button type="submit" variant="primary">{{ __('Starten') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
