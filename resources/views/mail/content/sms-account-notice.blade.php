<p>{{ __('app.mail_hello', ['name' => $data['recipient_name']]) }}</p>

@if ($data['notice'] === 'sms_auto_top_up_failed' && $data['reason'] === 'monthly_cap_exceeded')
    <p>{{ __('app.sms_auto_top_up_monthly_cap_warning') }}</p>
@else
    <p>{{ __('app.mail_sms_account_notice_'.$data['notice'], ['studio' => $data['account_name']]) }}</p>
@endif

<p>
    <strong>{{ __('app.sms_account_balance') }}:</strong> {{ $data['balance'] }}<br>
    @if ($data['outstanding'] !== null)
        <strong>{{ __('app.sms_outstanding_credit') }}:</strong> {{ $data['outstanding'] }}
    @endif
</p>

@if (filled($data['reason']) && $data['reason'] !== 'monthly_cap_exceeded')
    <p>{{ __('app.reason') }}: {{ $data['reason'] }}</p>
@endif

<x-mail::button :url="$data['action_url']">
{{ __('app.open_sms_account') }}
</x-mail::button>
