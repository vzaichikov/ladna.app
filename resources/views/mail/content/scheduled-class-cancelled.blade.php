<p>{{ __('app.mail_hello', ['name' => $data['recipient_name']]) }}</p>

<p>{{ __('app.mail_scheduled_class_cancelled_intro', ['studio' => $data['account_name']]) }}</p>

@include('mail.partials.details', ['rows' => [
    __('app.mail_detail_class') => $data['class_title'] ?? null,
    __('app.mail_detail_time') => $data['class_time'] ?? null,
    __('app.mail_detail_location') => $data['location_name'] ?? null,
    __('app.mail_detail_room') => $data['room_name'] ?? null,
    __('app.mail_detail_trainer') => $data['trainer_name'] ?? null,
]])

@if (! empty($data['action_url']))
<x-mail::button :url="$data['action_url']">
{{ __('app.mail_button_open_schedule') }}
</x-mail::button>
@endif
