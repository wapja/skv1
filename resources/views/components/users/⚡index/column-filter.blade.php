@switch($key)
    @case('status')
        <flux:select wire:model.live="filters.status" size="sm">
            <option value="">{{ __('Alle') }}</option>
            <option value="active">{{ __('Actief') }}</option>
            <option value="pending_activation">{{ __('Wachtend') }}</option>
            <option value="disabled">{{ __('Uitgeschakeld') }}</option>
        </flux:select>
    @break

    @case('locale')
        <flux:select wire:model.live="filters.locale" size="sm">
            <option value="">{{ __('Alle') }}</option>
            <option value="nl">nl</option>
            <option value="en">en</option>
        </flux:select>
    @break

    @case('start_date')
    @case('end_date')
        <flux:input type="date"
            wire:model.live.debounce.300ms="filters.{{ $key }}"
            size="sm" />
    @break

    @case('organisation')
    @break

    @default
        <flux:input
            wire:model.live.debounce.300ms="filters.{{ $key }}"
            placeholder="{{ __('Bevat…') }}"
            size="sm" />
@endswitch
