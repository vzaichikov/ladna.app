<p>{{ __('app.mail_event_hello', ['name' => $data['recipient_name']]) }}</p>
<p>{{ __('app.mail_event_payment_attention', ['event' => $data['event_title']]) }}</p>
<x-mail::button :url="$data['action_url']">{{ __('app.mail_event_open_order') }}</x-mail::button>
