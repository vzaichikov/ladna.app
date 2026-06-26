<p>{{ __('app.mail_hello', ['name' => $data['recipient_name']]) }}</p>

<p>{{ __('app.mail_customer_purchase_failed_intro', ['studio' => $data['account_name']]) }}</p>

@include('mail.partials.details', ['rows' => [
    __('app.mail_detail_pass') => $data['pass_name'] ?? null,
    __('app.mail_detail_status') => $data['status'] ?? null,
    __('app.mail_detail_amount') => $data['amount'] ?? null,
    __('app.mail_detail_reason') => $data['failure_reason'] ?? null,
]])

@if (! empty($data['action_url']))
<x-mail::button :url="$data['action_url']">
{{ __('app.mail_button_open_price') }}
</x-mail::button>
@endif
