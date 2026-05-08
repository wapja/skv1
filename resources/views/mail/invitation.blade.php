<x-mail::message>
# {{ __('Je bent uitgenodigd') }}

{{ __('Je bent uitgenodigd om mee te werken in :app.', ['app' => config('app.name')]) }}

{{ __('Klik op de knop hieronder om je account te activeren. Deze link verloopt op :date.', ['date' => $expiresAt->isoFormat('LLL')]) }}

<x-mail::button :url="$url">
{{ __('Account activeren') }}
</x-mail::button>

{{ __('Werkt de knop niet, kopieer dan deze link in je browser:') }}

{{ $url }}

{{ __('Met vriendelijke groet,') }}<br>
{{ config('app.name') }}
</x-mail::message>
