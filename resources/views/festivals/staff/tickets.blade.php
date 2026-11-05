@extends('layouts.app')
@section('title', __('app.festival_tickets').' - '.$edition->title)
@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_tickets')" :copy="__('app.festival_journey.hub_copy')">
        <x-slot:actions>
            @if ($workspacePermissions['finance'])
                <x-ui.button :href="route('dashboard.accounts.festivals.tickets.issue', [$account, $edition])">{{ __('app.festival_issue_tickets') }}</x-ui.button>
            @elseif ($workspacePermissions['ticket_check_in'] || $workspacePermissions['door_staff'])
                <x-ui.button :href="route('dashboard.accounts.festivals.scanner', [$account, $edition])">{{ __('app.festival_open_scanner') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" data-festival-ticket-hub>
        @if ($workspacePermissions['finance'])
            @foreach ([
                ['admission-types.index', 'festival_ticket_types_tab', 'types_copy', 'ticket'],
                ['tickets.sold', 'festival_bought_tickets_tab', 'sold_copy', 'tickets'],
                ['ticket-orders.index', 'festival_ticket_orders_tab', 'orders_copy', 'receipt'],
                ['hall-layout.edit', 'festival_seating_admin.title', 'layout_copy', 'layout-grid'],
                ['tickets.issue', 'festival_issue_tickets', 'issue_copy', 'plus'],
                ['promo-codes.index', 'promo_codes', 'promos_copy', 'tag'],
            ] as [$destination, $label, $copy, $icon])
                <a href="{{ route('dashboard.accounts.festivals.'.$destination, [$account, $edition]) }}" class="crm-focus rounded-2xl border border-stone-200 bg-white p-6 shadow-crm transition hover:border-brand-300" data-ticket-hub-card>
                    <x-ui.icon :name="$icon" class="mb-4 h-6 w-6 text-brand-600" />
                    <h2 class="text-lg font-semibold">{{ __('app.'.$label) }}</h2>
                    <p class="mt-2 text-sm text-slate-600">{{ __('app.festival_journey.'.$copy) }}</p>
                    @if ($destination === 'hall-layout.edit')
                        <span class="crm-status-muted mt-3">{{ $edition->assigned_seating_enabled ? __('app.festival_journey.on') : ($edition->seating_floors ? __('app.festival_journey.draft') : __('app.festival_journey.off')) }}</span>
                    @elseif ($destination === 'tickets.sold')
                        <p class="mt-3 text-sm font-semibold">{{ __('app.festival_tickets_issued') }}: {{ $admissionReport['tickets'] }}</p>
                    @endif
                </a>
            @endforeach
        @else
            @if ($workspacePermissions['ticket_check_in'] || $workspacePermissions['door_staff'])
                <a class="crm-focus rounded-2xl bg-white p-6 shadow-crm" href="{{ route('dashboard.accounts.festivals.scanner', [$account, $edition]) }}">{{ __('app.festival_open_scanner') }}</a>
            @endif
            @if ($workspacePermissions['door_staff'])
                <a class="crm-focus rounded-2xl bg-white p-6 shadow-crm" href="{{ route('dashboard.accounts.festivals.attendance', [$account, $edition]) }}">{{ __('app.festival_entrance_monitor') }}</a>
            @endif
        @endif
    </div>
</x-festivals.staff.workspace>
@endsection
