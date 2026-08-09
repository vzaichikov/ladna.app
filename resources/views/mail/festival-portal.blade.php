<x-mail::message>
# {{ $greeting }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($actionLabel && $actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>
@endif

{{ __('app.festival_mail_footer', locale: $messageLocale) }}
</x-mail::message>
