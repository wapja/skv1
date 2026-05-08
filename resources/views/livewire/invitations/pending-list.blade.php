<div>
    @if ($pending->isEmpty())
        <flux:callout variant="secondary" icon="envelope">
            {{ __('Er staan geen openstaande uitnodigingen.') }}
        </flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('E-mailadres') }}</flux:table.column>
                <flux:table.column>{{ __('Uitgenodigd door') }}</flux:table.column>
                <flux:table.column>{{ __('Verloopt op') }}</flux:table.column>
                <flux:table.column>{{ __('Acties') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($pending as $invitation)
                    <flux:table.row :key="$invitation->id">
                        <flux:table.cell>{{ $invitation->user->email }}</flux:table.cell>
                        <flux:table.cell>{{ $invitation->inviter?->email ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $invitation->expires_at->isoFormat('LLL') }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
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
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
