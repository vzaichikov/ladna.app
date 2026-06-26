<p>{{ __('app.mail_hello', ['name' => $data['recipient_name']]) }}</p>

<p>{{ __('app.mail_class_pass_adjusted_intro', ['studio' => $data['account_name']]) }}</p>

@include('mail.partials.details', ['rows' => [
    __('app.mail_detail_pass') => $data['pass_name'] ?? null,
    __('app.mail_detail_code') => $data['pass_code'] ?? null,
    __('app.mail_detail_delta') => $data['sessions_delta'] ?? null,
    __('app.mail_detail_previous_sessions') => $data['previous_sessions_count'] ?? null,
    __('app.mail_detail_new_sessions') => $data['new_sessions_count'] ?? null,
    __('app.mail_detail_reason') => $data['reason'] ?? null,
]])

@if (! empty($data['action_url']))
<x-mail::button :url="$data['action_url']">
{{ __('app.mail_button_open_customer_dashboard') }}
</x-mail::button>
@endif
