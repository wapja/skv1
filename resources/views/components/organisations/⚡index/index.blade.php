<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Organisaties') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Beheer alle tenants in het systeem.') }}
            </flux:text>
        </div>
        @can('create', App\Models\Organisation::class)
            <flux:button variant="primary" :href="route('organisations.create')" wire:navigate>
                {{ __('Nieuwe organisatie') }}
            </flux:button>
        @endcan
    </div>

    @if ($organisations->isEmpty())
        <flux:callout variant="secondary" icon="building-office">{{ __('Geen organisaties gevonden.') }}</flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Naam') }}</flux:table.column>
                <flux:table.column>{{ __('Slug') }}</flux:table.column>
                <flux:table.column>{{ __('Acties') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($organisations as $organisation)
                    <flux:table.row :key="$organisation->id">
                        <flux:table.cell>{{ $organisation->name }}</flux:table.cell>
                        <flux:table.cell>{{ $organisation->slug }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                @can('update', $organisation)
                                    <flux:button size="sm" variant="ghost" :href="route('organisations.edit', $organisation)" wire:navigate>
                                        {{ __('Bewerken') }}
                                    </flux:button>
                                @endcan
                                @can('delete', $organisation)
                                    <flux:button size="sm" variant="danger" wire:click="delete({{ $organisation->id }})">
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
</div>
