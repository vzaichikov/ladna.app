@extends('layouts.app')

@section('title', __('app.event_scanner').' - '.$event->title)

@section('content')
<div
    class="mx-auto max-w-7xl space-y-6"
    data-event-admin-page
    data-event-scanner
    data-scan-url="{{ route('dashboard.accounts.events.scanner.scan', [$account, $event]) }}"
    data-csrf-token="{{ csrf_token() }}"
    data-camera-error="{{ __('app.event_camera_error') }}"
    data-camera-automatic="{{ __('app.event_camera_automatic') }}"
    data-camera-name-template="{{ __('app.event_camera_name', ['number' => '__NUMBER__']) }}"
    data-request-error="{{ __('app.event_scanner_request_failed') }}"
    data-check-out-reason="{{ __('app.event_check_out_reason_prompt') }}"
    data-torch-enable="{{ __('app.event_torch_enable') }}"
    data-torch-disable="{{ __('app.event_torch_disable') }}"
>
    <header>
        <h1 class="crm-page-title">{{ $event->title }}</h1>
        <p class="crm-page-copy">{{ __('app.event_scanner_online_only') }}</p>
    </header>

    <x-ui.event-navigation :account="$account" :event="$event" active="scanner" />

    <section class="grid gap-5 lg:grid-cols-[1.2fr_.8fr]">
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-slate-950 shadow-crm">
            <video data-scanner-video class="aspect-[3/4] w-full object-cover sm:aspect-video" playsinline muted></video>
            <div class="grid gap-3 bg-white p-4 sm:grid-cols-[1fr_auto_auto]">
                <select data-scanner-camera class="crm-field mt-0"><option>{{ __('app.event_camera_loading') }}</option></select>
                <x-ui.button type="button" data-scanner-start>{{ __('app.event_scanner_start') }}</x-ui.button>
                <x-ui.button type="button" data-scanner-torch variant="secondary" class="hidden" aria-pressed="false">{{ __('app.event_torch_enable') }}</x-ui.button>
            </div>
        </div>
        <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <h2 class="text-xl font-semibold">{{ __('app.event_manual_code') }}</h2>
            <form data-scanner-manual class="mt-4 space-y-3"><input name="code" required autocomplete="off" class="crm-field mt-0 font-mono uppercase tracking-wide" placeholder="EVT-XXXX-XXXX"><x-ui.button type="submit" class="w-full">{{ __('app.event_check_in') }}</x-ui.button></form>
            <div data-scanner-result class="mt-5 hidden rounded-xl p-4 text-sm font-semibold" role="status"></div>
        </div>
    </section>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><h2 class="text-xl font-semibold">{{ __('app.event_door_list') }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('app.event_door_list_no_contacts') }}</p></div><form method="GET" class="flex flex-col gap-2 sm:flex-row"><input name="search" value="{{ $search }}" placeholder="{{ __('app.search') }}" class="crm-field mt-0 min-w-0 sm:w-64"><x-ui.button type="submit" variant="secondary">{{ __('app.search') }}</x-ui.button></form></div>
        <div class="mt-5 divide-y divide-stone-100">
            @foreach ($tickets as $ticket)
                <div class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between" data-ticket-row>
                    <div><strong>{{ $ticket->order?->buyer_name }}</strong><p class="mt-1 text-sm text-slate-500">{{ $ticket->ticketType?->name }} · <span class="font-mono">{{ $ticket->code }}</span></p></div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="{{ $ticket->status !== \App\Enums\EventTicketStatus::Valid ? 'crm-status-danger' : ($ticket->is_checked_in ? 'crm-status-active' : 'crm-status-muted') }}">{{ $ticket->status !== \App\Enums\EventTicketStatus::Valid ? __('app.event_ticket_status_'.$ticket->status->value) : ($ticket->is_checked_in ? __('app.event_checked_in') : __('app.event_not_checked_in')) }}</span>
                        @if ($ticket->status === \App\Enums\EventTicketStatus::Valid)
                            @if ($ticket->is_checked_in)
                                <x-ui.button type="button" variant="secondary" size="sm" data-door-checkout data-checkout-url="{{ route('dashboard.accounts.events.scanner.check-out', [$account, $event, $ticket]) }}">{{ __('app.event_check_out') }}</x-ui.button>
                            @else
                                <x-ui.button type="button" variant="secondary" size="sm" data-door-checkin data-ticket-code="{{ $ticket->code }}">{{ __('app.event_check_in') }}</x-ui.button>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        {{ $tickets->links() }}
    </section>
</div>
@endsection
