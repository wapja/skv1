<div class="space-y-8">
    <flux:button :href="route('roles.index')" icon="arrow-left" variant="ghost" wire:navigate>
        {{ __('Terug') }}
    </flux:button>

    <flux:heading size="xl">
        {{ $role ? __('Rol bewerken: :name', ['name' => $role->name]) : __('Nieuwe rol') }}
    </flux:heading>

    <flux:card>
        <form wire:submit="save" class="space-y-6">
            <flux:input wire:model="name" label="{{ __('Rolnaam') }}" required />

            <fieldset>
                <flux:legend>{{ __('Permissies') }}</flux:legend>
                <div class="grid grid-cols-2 gap-2 mt-2">
                    @foreach ($permissions as $permission)
                        <flux:checkbox
                            wire:model="selectedPermissions"
                            value="{{ $permission->id }}"
                            label="{{ $permission->name }}" />
                    @endforeach
                </div>
            </fieldset>

            <flux:button type="submit" variant="primary">{{ __('Opslaan') }}</flux:button>
        </form>
    </flux:card>
</div>
