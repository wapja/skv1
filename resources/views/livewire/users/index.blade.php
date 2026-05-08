<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Gebruikers') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Beheer de gebruikers van :org.', ['org' => tenant()?->name ?? config('app.name')]) }}
            </flux:text>
        </div>
        @can('create', App\Models\User::class)
            <livewire:invitations.send />
        @endcan
    </div>

    <flux:select wire:model.live="statusFilter" label="{{ __('Status') }}">
        <option value="">{{ __('Alle statussen') }}</option>
        <option value="active">{{ __('Actief') }}</option>
        <option value="pending_activation">{{ __('Wachtend op activering') }}</option>
        <option value="disabled">{{ __('Uitgeschakeld') }}</option>
    </flux:select>

    @if ($users->isEmpty())
        <flux:callout variant="secondary" icon="users">{{ __('Geen gebruikers gevonden.') }}</flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Naam') }}</flux:table.column>
                <flux:table.column>{{ __('E-mailadres') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Acties') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($users as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell>{{ $user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>{{ __($user->status) }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                @can('update', $user)
                                    <flux:button size="sm" variant="ghost" :href="route('users.edit', $user)" wire:navigate>
                                        {{ __('Bewerken') }}
                                    </flux:button>
                                @endcan
                                @can('delete', $user)
                                    <flux:button size="sm" variant="danger" wire:click="delete({{ $user->id }})">
                                        {{ __('Verwijderen') }}
                                    </flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <livewire:invitations.pending-list />
</div>
