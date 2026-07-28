<p>{{ __('app.mail_event_hello', ['name' => $data['recipient_name']]) }}</p>
<p>{{ __('app.mail_event_tickets_ready', ['event' => $data['event_title']]) }}</p>
<p><strong>{{ $data['event_time'] }}</strong><br>{{ $data['event_venue'] }}</p>
@foreach ($data['tickets'] as $ticket)
<p><strong>{{ $ticket['type'] }}</strong> — {{ $ticket['code'] }}</p>
<p><img src="{{ $ticket['qr_url'] }}" width="240" height="240" alt="{{ __('app.event_ticket_qr') }}"></p>
@endforeach
<x-mail::button :url="$data['action_url']">{{ __('app.mail_event_open_tickets') }}</x-mail::button>
