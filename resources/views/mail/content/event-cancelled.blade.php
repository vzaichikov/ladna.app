<p>{{ __('app.mail_event_hello', ['name' => $data['recipient_name']]) }}</p>
<p>{{ __('app.mail_event_cancelled', ['event' => $data['event_title']]) }}</p>
@if ($data['amount'] ?? null)<p>{{ __('app.mail_event_refund_contact', ['amount' => $data['amount']]) }}</p>@endif
<x-mail::button :url="$data['action_url']">{{ __('app.mail_event_open_order') }}</x-mail::button>
