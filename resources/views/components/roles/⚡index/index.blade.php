<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Rollen en permissies') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Beheer welke acties elke rol mag uitvoeren binnen :org.', ['org' => tenant()?->name ?? config('app.name')]) }}
            </flux:text>
        </div>
        @can('create', Spatie\Permission\Models\Role::class)
            <flux:button variant="primary" :href="route('roles.create')" wire:navigate>
                {{ __('Nieuwe rol') }}
            </flux:button>
        @endcan
    </div>

    @if ($roles->isEmpty())
        <flux:callout variant="secondary" icon="shield-check">{{ __('Geen rollen gevonden.') }}</flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Rol') }}</flux:table.column>
                @if (auth()->user()?->isSuperAdmin())
                    <flux:table.column>{{ __('Organisatie') }}</flux:table.column>
                @endif
                <flux:table.column>{{ __('Permissies') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Acties') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($roles as $role)
                    <flux:table.row :key="$role->id">
                        <flux:table.cell>{{ $role->name }}</flux:table.cell>
                        @if (auth()->user()?->isSuperAdmin())
                            <flux:table.cell>
                                {{ $role->team?->name ?? __('Geen organisatie') }}
                            </flux:table.cell>
                        @endif
                        <flux:table.cell>{{ $role->permissions->pluck('name')->join(', ') ?: '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($role->team_id === null)
                                <flux:badge>{{ __('Geen organisatie') }}</flux:badge>
                            @else
                                <flux:badge variant="primary">{{ __('Aangepast') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                @can('update', $role)
                                    <flux:button size="sm" variant="ghost" :href="route('roles.edit', $role)" wire:navigate>
                                        {{ __('Bewerken') }}
                                    </flux:button>
                                @endcan
                                @can('delete', $role)
                                    @if ($role->users_count > 0)
                                        <flux:tooltip :content="$role->users_count === 1
                                            ? __('Niet verwijderbaar — gekoppeld aan 1 gebruiker')
                                            : __('Niet verwijderbaar — gekoppeld aan :count gebruikers', ['count' => $role->users_count])">
                                            <flux:button size="sm" variant="danger" disabled>
                                                {{ __('Verwijderen') }}
                                            </flux:button>
                                        </flux:tooltip>
                                    @else
                                        <flux:button size="sm" variant="danger" wire:click="deleteRole({{ $role->id }})">
                                            {{ __('Verwijderen') }}
                                        </flux:button>
                                    @endif
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
