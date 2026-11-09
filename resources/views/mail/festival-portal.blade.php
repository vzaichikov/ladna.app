<x-mail::message>
# {{ $greeting }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@foreach ($tickets as $ticket)
<p><strong>{{ $ticket['type'] }}</strong> — {{ $ticket['code'] }}<br>{{ $ticket['seat'] }}</p>
<p><img src="{{ $ticket['qr_url'] }}" width="240" height="240" style="max-width: 100%; height: auto;" alt="{{ __('app.festival_ticket_qr', locale: $messageLocale) }}"></p>
@endforeach
@if ($tickets !== [])
{{ __('app.festival_ticket_email_pdf', locale: $messageLocale) }}

@endif
@if ($actionLabel && $actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>
@endif

{{ __('app.festival_mail_footer', locale: $messageLocale) }}
</x-mail::message>
