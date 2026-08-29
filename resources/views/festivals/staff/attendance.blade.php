@extends('layouts.app')

@section('title', __('app.festival_entrance_monitor').' - '.$festivalEdition->title)

@section('content')
<x-festivals.staff.workspace :account="$account" :edition="$festivalEdition" :permissions="$workspacePermissions">
    <div
        class="w-full min-w-0 space-y-4"
        data-event-scanner
        data-event-attendance
        data-attendance-url="{{ route('dashboard.accounts.festivals.attendance.data', [$account, $festivalEdition]) }}"
        data-scan-url="{{ route('dashboard.accounts.festivals.scanner.scan', [$account, $festivalEdition]) }}"
        data-csrf-token="{{ csrf_token() }}"
        data-request-error="{{ __('app.festival_scanner_request_failed') }}"
        data-poll-interval="5000"
        data-live-label="{{ __('app.entrance_monitor_live') }}"
    >
        <x-ui.page-header :title="__('app.festival_entrance_monitor')" :copy="__('app.entrance_monitor_copy')">
            <x-slot:actions>
                <x-ui.button :href="route('dashboard.accounts.festivals.scanner', [$account, $festivalEdition])" variant="secondary"><x-ui.icon name="qr-code" class="h-4 w-4" />{{ __('app.festival_open_scanner') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (isset($entranceTools))
            <x-ui.entrance-tools
                :search-url="$entranceTools['search_url']"
                :cash-sale-url="$entranceTools['cash_sale_url']"
                :card-sale-url="$entranceTools['card_sale_url']"
                :ticket-types="$entranceTools['ticket_types']"
                :payment-providers="$entranceTools['payment_providers']"
                :currency="$festivalEdition->currency"
                :search-label="__('app.festival_entrance_search_people')"
                :search-hint="__('app.festival_entrance_search_hint')"
                :search-placeholder="__('app.festival_entrance_search_placeholder')"
                :no-people-label="__('app.festival_entrance_no_people')"
            />
        @endif

        <x-ui.entrance-monitor :overview="$overview" />
        <x-ui.ticket-scanner-modal />
    </div>
</x-festivals.staff.workspace>
@endsection
