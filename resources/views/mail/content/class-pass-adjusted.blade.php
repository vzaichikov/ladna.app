<p>{{ __('app.mail_hello', ['name' => $data['recipient_name']]) }}</p>

<p>{{ __('app.mail_class_pass_adjusted_intro', ['studio' => $data['account_name']]) }}</p>

@include('mail.partials.details', ['rows' => [
    __('app.mail_detail_pass') => $data['pass_name'] ?? null,
    __('app.mail_detail_code') => $data['pass_code'] ?? null,
    __('app.mail_detail_delta') => $data['sessions_delta'] ?? null,
    __('app.mail_detail_previous_sessions') => $data['previous_sessions_count'] ?? null,
    __('app.mail_detail_new_sessions') => $data['new_sessions_count'] ?? null,
    __('app.mail_detail_days_delta') => $data['days_delta'] ?? null,
    __('app.mail_detail_previous_validity_days') => $data['previous_validity_days'] ?? null,
    __('app.mail_detail_new_validity_days') => $data['new_validity_days'] ?? null,
    __('app.mail_detail_previous_status') => $data['previous_status'] ?? null,
    __('app.mail_detail_new_status') => $data['new_status'] ?? null,
    __('app.mail_detail_freeze_started') => $data['freeze_started_at'] ?? null,
    __('app.mail_detail_freeze_finished') => $data['freeze_finished_at'] ?? null,
    __('app.mail_detail_freeze_days') => $data['freeze_days_count'] ?? null,
    __('app.mail_detail_reason') => $data['reason'] ?? null,
]])

@if (! empty($data['action_url']))
<x-mail::button :url="$data['action_url']">
{{ __('app.mail_button_open_customer_dashboard') }}
</x-mail::button>
@endif
