<div class="space-y-6">
    <div>
        <flux:heading size="xl">{{ __('Activiteitenlog') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
            {{ __('Wie heeft wat gedaan, wanneer.') }}
        </flux:text>
    </div>

    <div class="grid gap-4 md:grid-cols-4">
        <flux:select wire:model.live="logFilter" label="{{ __('Logboek') }}">
            <option value="">{{ __('Alle') }}</option>
            @foreach ($logs as $log)
                <option value="{{ $log }}">{{ $log }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="actorFilter" label="{{ __('Actor') }}">
            <option value="">{{ __('Iedereen') }}</option>
            @foreach ($actors as $actor)
                <option value="{{ $actor->id }}">{{ $actor->email }}</option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live="fromDate" type="date" label="{{ __('Van') }}" />
        <flux:input wire:model.live="toDate" type="date" label="{{ __('Tot') }}" />
    </div>

    @if ($activities->isEmpty())
        <flux:callout variant="secondary" icon="document-text">{{ __('Geen activiteiten gevonden.') }}</flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Tijdstip') }}</flux:table.column>
                <flux:table.column>{{ __('Logboek') }}</flux:table.column>
                <flux:table.column>{{ __('Gebeurtenis') }}</flux:table.column>
                <flux:table.column>{{ __('Door') }}</flux:table.column>
                <flux:table.column>{{ __('Op') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($activities as $activity)
                    <flux:table.row :key="$activity->id">
                        <flux:table.cell>{{ $activity->created_at->isoFormat('LLL') }}</flux:table.cell>
                        <flux:table.cell>{{ $activity->log_name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $activity->description }}</flux:table.cell>
                        <flux:table.cell>{{ $activity->causer?->email ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $activity->subject?->email ?? $activity->subject_type ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        {{ $activities->links() }}
    @endif
</div>
