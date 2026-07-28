<p>{{ __('app.mail_event_hello', ['name' => $data['recipient_name']]) }}</p>
<p>{{ __('app.mail_event_updated', ['event' => $data['event_title']]) }}</p>
<p><strong>{{ $data['event_time'] }}</strong><br>{{ $data['event_venue'] }}</p>
<x-mail::button :url="$data['action_url']">{{ __('app.mail_event_open_tickets') }}</x-mail::button>
