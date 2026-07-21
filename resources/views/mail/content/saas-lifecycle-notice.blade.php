<p>{{ __('app.mail_hello', ['name' => $data['recipient_name']]) }}</p>

<p>{{ __('app.mail_saas_'.$data['notice_type'].'_intro', ['studio' => $data['account_name'], ...($data['notice_parameters'] ?? [])]) }}</p>

@include('mail.partials.details', ['rows' => [
    __('app.mail_detail_plan') => $data['plan_name'] ?? null,
    __('app.mail_detail_date') => data_get($data, 'notice_parameters.date'),
    __('app.mail_detail_amount') => data_get($data, 'notice_parameters.amount'),
    __('app.mail_detail_locations') => data_get($data, 'notice_parameters.locations'),
]])

@if (! empty($data['action_url']))
<x-mail::button :url="$data['action_url']">
{{ __('app.mail_button_open_tariff') }}
</x-mail::button>
@endif
