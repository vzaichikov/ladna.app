@extends('layouts.app')

@section('title', __('app.festival_entrance_monitor').' - '.$festivalEdition->title)

@section('content')
<x-festivals.staff.workspace :account="$account" :edition="$festivalEdition" :permissions="$workspacePermissions">
    <div
        class="w-full min-w-0 space-y-4"
        data-event-scanner
        @if ($attendanceView !== 'hall' || ! $hall['applicable']) data-event-attendance @endif
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

        @if ($hall['applicable'])
            <nav class="flex gap-2" aria-label="{{ __('app.festival_entrance_monitor') }}">
                @foreach (['guests', 'hall'] as $view)
                    <x-ui.button :href="route('dashboard.accounts.festivals.attendance', [$account, $festivalEdition, 'view' => $view])" :variant="$attendanceView === $view ? 'primary' : 'secondary'">{{ __('app.festival_journey.'.$view) }}</x-ui.button>
                @endforeach
            </nav>
        @endif

        @if (isset($entranceTools))
            <x-ui.entrance-tools
                :search-url="$entranceTools['search_url']"
                :cash-sale-url="$entranceTools['cash_sale_url']"
                :card-sale-url="$entranceTools['card_sale_url']"
                :ticket-types="$entranceTools['ticket_types']"
                :seat-chart="$entranceTools['seat_chart']"
                :seat-availability-url="$entranceTools['seat_availability_url']"
                :payment-providers="$entranceTools['payment_providers']"
                :currency="$festivalEdition->currency"
                :search-label="__('app.festival_entrance_search_people')"
                :search-hint="__('app.festival_entrance_search_hint')"
                :search-placeholder="__('app.festival_entrance_search_placeholder')"
                :no-people-label="__('app.festival_entrance_no_people')"
            />
        @endif

        @if ($attendanceView === 'hall' && $hall['applicable'])
            <p class="text-sm text-slate-600">{{ __('app.festival_journey.hall_hint') }}</p>
            <div class="flex flex-wrap gap-4 text-sm" data-hall-other-counters>
                <span>{{ __('app.festival_journey.general_admission') }}: <strong data-hall-general>{{ $generalAdmissionCount }}</strong></span>
                <span>{{ __('app.festival_journey.participants') }}: <strong data-hall-participants>{{ $overview['participants']['passed'] }}/{{ $overview['participants']['total'] }}</strong></span>
                <span>{{ __('app.festival_journey.helpers') }}: <strong data-hall-helpers>{{ $overview['helpers']['passed'] }}/{{ $overview['helpers']['total'] }}</strong></span>
                <span>{{ __('app.festival_journey.capacity') }}: <strong>{{ count($hall['seats']) }}</strong></span>
            </div>
            <x-festivals.seat-map :projection="$hall" :refresh-url="route('dashboard.accounts.festivals.attendance.data', [$account, $festivalEdition, 'view' => 'hall', 'state_only' => 1])" />
        @else
            <x-ui.entrance-monitor :overview="$overview" />
        @endif
        <x-ui.ticket-scanner-modal />
    </div>
</x-festivals.staff.workspace>
@endsection
