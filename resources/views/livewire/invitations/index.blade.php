<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Uitgenodigde gebruikers') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Verzonden uitnodigingen voor :org.', ['org' => tenant()?->name ?? config('app.name')]) }}
            </flux:text>
        </div>
        <flux:button :href="route('users.index')" variant="ghost" wire:navigate>
            {{ __('Terug naar gebruikers') }}
        </flux:button>
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
            @foreach (App\Livewire\Invitations\Index::PER_PAGE_OPTIONS as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </flux:select>
    </div>

    @if ($invitations->total() === 0 && $this->hasNoFilters())
        <flux:callout variant="secondary" icon="envelope">{{ __('Er staan geen uitnodigingen.') }}</flux:callout>
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
                                @include('livewire.invitations.partials.column-filter', ['key' => $key])
                            </flux:table.cell>
                        @endif
                    @endforeach
                    <flux:table.cell></flux:table.cell>
                </flux:table.row>

                @foreach ($invitations as $invitation)
                    @php($status = $invitation->status)
                    <flux:table.row :key="$invitation->id">
                        @foreach ($columns as $key => $label)
                            @if (in_array($key, $selectedColumns, true))
                                <flux:table.cell>
                                    @switch($key)
                                        @case('email')      {{ $invitation->user?->email ?? '—' }} @break
                                        @case('name')       {{ $invitation->user?->name ?? '—' }} @break
                                        @case('status')     {{ __($status) }} @break
                                        @case('inviter')    {{ $invitation->inviter?->email ?? '—' }} @break
                                        @case('expires_at') {{ $invitation->expires_at?->isoFormat('LLL') ?? '—' }} @break
                                        @case('sent_at')    {{ $invitation->created_at?->isoFormat('LLL') ?? '—' }} @break
                                    @endswitch
                                </flux:table.cell>
                            @endif
                        @endforeach
                        <flux:table.cell>
                            <div class="flex gap-2">
                                @if ($status === 'pending')
                                    @can('invitations.send')
                                        <flux:button size="sm" variant="ghost" wire:click="resend({{ $invitation->id }})">
                                            {{ __('Herinnering') }}
                                        </flux:button>
                                    @endcan
                                    @can('invitations.cancel')
                                        <flux:button size="sm" variant="danger" wire:click="cancel({{ $invitation->id }})">
                                            {{ __('Intrekken') }}
                                        </flux:button>
                                    @endcan
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        @if ($invitations->total() === 0)
            <flux:callout variant="secondary" icon="funnel">
                {{ __('Geen uitnodigingen met deze filters.') }}
                <flux:button size="sm" variant="ghost" wire:click="clearFilters">
                    {{ __('Filters wissen') }}
                </flux:button>
            </flux:callout>
        @endif

        {{ $invitations->links() }}
    @endif
</div>
