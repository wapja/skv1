<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Gebruikers') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Beheer de gebruikers van :org.', ['org' => tenant()?->name ?? config('app.name')]) }}
            </flux:text>
        </div>
        @can('invitations.send')
            <flux:button :href="route('invitations.index')" variant="ghost" wire:navigate>
                {{ __('Uitgenodigde gebruikers') }}
            </flux:button>
        @endcan
        @can('create', App\Models\User::class)
            <livewire:invitations.send />
        @endcan
    </div>

    <div class="flex items-end justify-between gap-4">
        <flux:dropdown>
            <flux:button icon="adjustments-horizontal" variant="ghost">
                {{ __('Kolommen') }}
            </flux:button>
            <flux:menu>
                <flux:menu.checkbox.group wire:model.live="selectedColumns">
                    @foreach ($columns as $key => $label)
                        <flux:menu.checkbox value="{{ $key }}">{{ $label }}</flux:menu.checkbox>
                    @endforeach
                </flux:menu.checkbox.group>
            </flux:menu>
        </flux:dropdown>

        <flux:select wire:model.live="perPage" label="{{ __('Per pagina') }}">
            @foreach ($this->perPageOptions() as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </flux:select>
    </div>

    @if ($users->total() === 0 && $this->hasNoFilters())
        <flux:callout variant="secondary" icon="users">{{ __('Geen gebruikers gevonden.') }}</flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                @foreach ($columns as $key => $label)
                    @if (in_array($key, $selectedColumns, true))
                        <flux:table.column
                            sortable
                            :sorted="$sortColumn === $key"
                            :direction="$sortColumn === $key ? $sortDirection : null"
                            wire:click="sort('{{ $key }}')"
                            style="cursor:pointer">
                            {{ $label }}
                        </flux:table.column>
                    @endif
                @endforeach
                <flux:table.column>{{ __('Acties') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                <flux:table.row class="bg-zinc-50/60 dark:bg-white/5">
                    @foreach ($columns as $key => $label)
                        @if (in_array($key, $selectedColumns, true))
                            <flux:table.cell class="py-2">
                                @include('components.users.⚡index.column-filter', ['key' => $key])
                            </flux:table.cell>
                        @endif
                    @endforeach
                    <flux:table.cell></flux:table.cell>
                </flux:table.row>

                @foreach ($users as $user)
                    <flux:table.row :key="$user->id">
                        @foreach ($columns as $key => $label)
                            @if (in_array($key, $selectedColumns, true))
                                <flux:table.cell>
                                    @switch($key)
                                        @case('name')
                                            {{ $user->name }}
                                        @break

                                        @case('status')
                                            {{ __($user->status) }}
                                        @break

                                        @case('start_date')
                                        @case('end_date')
                                            {{ $user->{$key}?->format('d-m-Y') }}
                                        @break

                                        @case('organisation')
                                            {{ $user->organisation?->name ?? '—' }}
                                        @break

                                        @default
                                            {{ $user->{$key} }}
                                    @endswitch
                                </flux:table.cell>
                            @endif
                        @endforeach
                        <flux:table.cell>
                            <div class="flex gap-2">
                                @can('update', $user)
                                    <flux:button size="sm" variant="ghost" :href="route('users.edit', $user)" wire:navigate>
                                        {{ __('Bewerken') }}
                                    </flux:button>
                                @endcan
                                @if (auth()->user()?->can('users.impersonate') && $user->id !== auth()->id() && ! $user->isSuperAdmin())
                                    <flux:button size="sm" variant="ghost" wire:click="$dispatch('open-impersonate', { userId: {{ $user->id }} })">
                                        {{ __('Impersoneren') }}
                                    </flux:button>
                                @endif
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

        @if ($users->total() === 0)
            <flux:callout variant="secondary" icon="funnel">
                {{ __('Geen gebruikers met deze filters.') }}
                <flux:button size="sm" variant="ghost" wire:click="clearFilters">
                    {{ __('Filters wissen') }}
                </flux:button>
            </flux:callout>
        @endif

        {{ $users->links() }}
    @endif

    <livewire:users.impersonate />
</div>
