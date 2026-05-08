<div class="space-y-8">
    <div>
        <flux:heading size="xl">{{ __('Rollen en permissies') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
            {{ __('Beheer welke acties elke rol mag uitvoeren binnen :org.', ['org' => tenant()?->name ?? config('app.name')]) }}
        </flux:text>
    </div>

    @can('create', Spatie\Permission\Models\Role::class)
        <flux:card>
            <flux:heading size="lg">{{ __('Nieuwe rol aanmaken') }}</flux:heading>
            <form wire:submit="createRole" class="mt-4 space-y-4">
                <flux:input wire:model="newRoleName" label="{{ __('Rolnaam') }}" placeholder="editor" required />
                <fieldset>
                    <flux:legend>{{ __('Permissies') }}</flux:legend>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        @foreach ($permissions as $permission)
                            <flux:checkbox
                                wire:model="newRolePermissions"
                                value="{{ $permission->id }}"
                                label="{{ $permission->name }}" />
                        @endforeach
                    </div>
                </fieldset>
                <flux:button type="submit" variant="primary">{{ __('Aanmaken') }}</flux:button>
            </form>
        </flux:card>
    @endcan

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Rol') }}</flux:table.column>
            <flux:table.column>{{ __('Permissies') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Acties') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($roles as $role)
                <flux:table.row :key="$role->id">
                    <flux:table.cell>{{ $role->name }}</flux:table.cell>
                    <flux:table.cell>{{ $role->permissions->pluck('name')->join(', ') ?: '—' }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($role->team_id === null)
                            <flux:badge>{{ __('Sjabloon') }}</flux:badge>
                        @else
                            <flux:badge variant="primary">{{ __('Aangepast') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @can('delete', $role)
                            <flux:button size="sm" variant="danger" wire:click="deleteRole({{ $role->id }})">
                                {{ __('Verwijderen') }}
                            </flux:button>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
