<p>{{ __('app.mail_hello', ['name' => $data['recipient_name']]) }}</p>

<p>{{ __('app.mail_class_pass_issued_intro', ['studio' => $data['account_name']]) }}</p>

@include('mail.partials.details', ['rows' => [
    __('app.mail_detail_pass') => $data['pass_name'] ?? null,
    __('app.mail_detail_code') => $data['pass_code'] ?? null,
    __('app.mail_detail_sessions') => $data['sessions_count'] ?? null,
    __('app.mail_detail_remaining_sessions') => $data['remaining_sessions_count'] ?? null,
    __('app.mail_detail_expires') => $data['expires_at'] ?? null,
    __('app.mail_detail_usable_until') => $data['usable_until_at'] ?? null,
    __('app.mail_detail_amount') => $data['amount'] ?? null,
]])

@if (! empty($data['action_url']))
<x-mail::button :url="$data['action_url']">
{{ __('app.mail_button_open_customer_dashboard') }}
</x-mail::button>
@endif
