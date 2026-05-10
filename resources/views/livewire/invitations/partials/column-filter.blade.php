@switch($key)
    @case('status')
        <flux:select wire:model.live="filters.status" size="sm">
            <option value="">{{ __('Alle') }}</option>
            <option value="pending">{{ __('pending') }}</option>
            <option value="accepted">{{ __('accepted') }}</option>
            <option value="expired">{{ __('expired') }}</option>
            <option value="cancelled">{{ __('cancelled') }}</option>
        </flux:select>
    @break

    @case('expires_at')
    @case('sent_at')
        <flux:input type="date"
            wire:model.live.debounce.300ms="filters.{{ $key }}"
            size="sm" />
    @break

    @default
        <flux:input
            wire:model.live.debounce.300ms="filters.{{ $key }}"
            placeholder="{{ __('Bevat…') }}"
            size="sm" />
@endswitch
