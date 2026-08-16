@extends('layouts.app')

@section('title', __('app.event_attendance').' - '.$event->title)

@section('content')
<div
    class="w-full min-w-0 space-y-6"
    data-event-admin-page
    data-event-scanner
    data-event-attendance
    data-attendance-url="{{ route('dashboard.accounts.events.attendance.data', [$account, $event]) }}"
    data-scan-url="{{ route('dashboard.accounts.events.scanner.scan', [$account, $event]) }}"
    data-csrf-token="{{ csrf_token() }}"
    data-request-error="{{ __('app.event_scanner_request_failed') }}"
    data-poll-interval="5000"
    data-live-label="{{ __('app.entrance_monitor_live') }}"
>
    <x-ui.event-navigation :account="$account" :event="$event" active="attendance" />

    @if (isset($entranceTools))
        <x-ui.entrance-tools
            :search-url="$entranceTools['search_url']"
            :cash-sale-url="$entranceTools['cash_sale_url']"
            :card-sale-url="$entranceTools['card_sale_url']"
            :ticket-types="$entranceTools['ticket_types']"
            :payment-providers="$entranceTools['payment_providers']"
            :currency="$event->currency"
        />
    @endif

    <x-ui.entrance-monitor :overview="$overview" />
    <x-ui.ticket-scanner-modal />
</div>
@endsection
