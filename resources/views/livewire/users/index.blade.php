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

    <div class="flex items-end justify-between gap-4">
        <div class="flex flex-wrap items-end gap-4">
            <flux:select wire:model.live="statusFilter" label="{{ __('Status') }}">
                <option value="">{{ __('Alle statussen') }}</option>
                <option value="active">{{ __('Actief') }}</option>
                <option value="pending_activation">{{ __('Wachtend op activering') }}</option>
                <option value="disabled">{{ __('Uitgeschakeld') }}</option>
            </flux:select>

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
        </div>

        <flux:select wire:model.live="perPage" label="{{ __('Per pagina') }}">
            @foreach (App\Livewire\Users\Index::PER_PAGE_OPTIONS as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </flux:select>
    </div>

    @if ($users->isEmpty())
        <flux:callout variant="secondary" icon="users">{{ __('Geen gebruikers gevonden.') }}</flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                @foreach ($columns as $key => $label)
                    @if (in_array($key, $selectedColumns, true))
                        <flux:table.column>{{ $label }}</flux:table.column>
                    @endif
                @endforeach
                <flux:table.column>{{ __('Acties') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
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

        {{ $users->links() }}
    @endif

    <livewire:invitations.pending-list />

    <livewire:users.impersonate />
</div>
