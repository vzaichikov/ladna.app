<p>{{ __('app.mail_hello', ['name' => $data['recipient_name']]) }}</p>

<p>{{ __('app.mail_sms_account_notice_'.$data['notice'], ['studio' => $data['account_name']]) }}</p>

<p>
    <strong>{{ __('app.sms_account_balance') }}:</strong> {{ $data['balance'] }}<br>
    @if ($data['outstanding'] !== null)
        <strong>{{ __('app.sms_outstanding_credit') }}:</strong> {{ $data['outstanding'] }}
    @endif
</p>

@if (filled($data['reason']))
    <p>{{ __('app.reason') }}: {{ $data['reason'] }}</p>
@endif

<x-mail::button :url="$data['action_url']">
{{ __('app.open_sms_account') }}
</x-mail::button>
