@extends('layouts.app')

@section('title', __('app.sms_delivery_log').' - '.__('app.app_name'))

@section('content')
    <div>
        <h1 class="crm-page-title">{{ __('app.sms_delivery_log') }}</h1>
        <p class="crm-page-copy">{{ __('app.sms_delivery_log_platform_copy') }}</p>
    </div>

    <x-ui.panel padding="lg" class="mt-6">
        <form method="GET" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label class="block">
                <span class="crm-label">{{ __('app.studio') }}</span>
                <select name="account_id" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected($filters['accountId'] === $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.sms_purpose') }}</span>
                <select name="purpose" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($purposes as $purpose)
                        <option value="{{ $purpose->value }}" @selected($filters['purpose'] === $purpose->value)>{{ __('app.sms_purpose_'.$purpose->value) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.status') }}</span>
                <select name="status" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ __('app.sms_delivery_status_'.$status->value) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.sms_source') }}</span>
                <select name="mode" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($modes as $mode)
                        <option value="{{ $mode->value }}" @selected($filters['mode'] === $mode->value)>{{ __('app.sms_mode_'.$mode->value) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.provider') }}</span>
                <select name="provider" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider }}" @selected($filters['provider'] === $provider)>{{ $provider }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.date_from') }}</span>
                <input name="date_from" type="date" class="crm-field" value="{{ $filters['dateFrom'] }}">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.date_to') }}</span>
                <input name="date_to" type="date" class="crm-field" value="{{ $filters['dateTo'] }}">
            </label>
            <div class="flex items-end gap-3">
                <x-ui.button type="submit">{{ __('app.filter') }}</x-ui.button>
                <x-ui.button :href="route('platform.sms-deliveries.index')" variant="secondary">{{ __('app.reset') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>

    <x-sms-delivery-table :deliveries="$deliveries" :show-account="true" />

    @if ($deliveries->hasPages())
        <div class="mt-6">{{ $deliveries->links() }}</div>
    @endif
@endsection
