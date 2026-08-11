@extends('layouts.app')

@section('title', __('app.festival_scanner').' - '.$festivalEdition->title)

@section('content')
<x-festivals.staff.workspace :account="$account" :edition="$festivalEdition" :permissions="$workspacePermissions">
    <div
        class="mx-auto max-w-5xl space-y-6"
        data-event-scanner
        data-scan-url="{{ route('dashboard.accounts.festivals.scanner.scan', [$account, $festivalEdition]) }}"
        data-csrf-token="{{ csrf_token() }}"
        data-camera-error="{{ __('app.festival_camera_error') }}"
        data-camera-name-template="{{ __('app.festival_camera_name', ['number' => '__NUMBER__']) }}"
        data-request-error="{{ __('app.festival_scanner_request_failed') }}"
        data-check-out-reason="{{ __('app.festival_check_out_reason_prompt') }}"
    >
        <x-ui.page-header :title="__('app.festival_scanner')" :copy="__('app.festival_scanner_online_only')" />

        <section class="grid gap-5 lg:grid-cols-[1.2fr_.8fr]">
            <div class="overflow-hidden rounded-2xl border border-stone-200 bg-slate-950 shadow-crm">
                <video data-scanner-video class="aspect-[3/4] w-full object-cover sm:aspect-video" playsinline muted></video>
                <div class="grid gap-3 bg-white p-4 sm:grid-cols-[1fr_auto_auto]">
                    <select data-scanner-camera class="crm-field mt-0"><option>{{ __('app.festival_camera_loading') }}</option></select>
                    <x-ui.button type="button" data-scanner-start>{{ __('app.festival_scanner_start') }}</x-ui.button>
                    <x-ui.button type="button" data-scanner-torch variant="secondary" class="hidden">{{ __('app.festival_torch') }}</x-ui.button>
                </div>
            </div>
            <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <h2 class="text-xl font-semibold">{{ __('app.festival_manual_code') }}</h2>
                <form data-scanner-manual class="mt-4 space-y-3">
                    <input name="code" required autocomplete="off" class="crm-field mt-0 font-mono uppercase tracking-wide" placeholder="FST-XXXX-XXXX">
                    <x-ui.button type="submit" class="w-full">{{ __('app.festival_check_in') }}</x-ui.button>
                </form>
                <div data-scanner-result class="mt-5 hidden rounded-xl p-4 text-sm font-semibold" role="status"></div>
            </div>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <h2 class="text-xl font-semibold">{{ __('app.festival_door_list') }}</h2>
                <form method="GET" class="flex gap-2"><input name="search" value="{{ $search }}" placeholder="{{ __('app.search') }}" class="crm-field mt-0 sm:w-64"><x-ui.button type="submit" variant="secondary">{{ __('app.search') }}</x-ui.button></form>
            </div>
            <div class="mt-5 divide-y divide-stone-100">
                @foreach ($tickets as $ticket)
                    <div class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><strong>{{ $ticket->order?->buyer_name }}</strong><p class="mt-1 text-sm text-slate-500">{{ $ticket->admissionType?->name }} · <span class="font-mono">{{ $ticket->code }}</span></p></div>
                        <div class="flex items-center gap-2">
                            <span class="{{ $ticket->status !== \App\Enums\FestivalTicketStatus::Valid ? 'crm-status-danger' : ($ticket->is_checked_in ? 'crm-status-active' : 'crm-status-muted') }}">{{ __('app.festival_ticket_status_'.$ticket->status->value) }} · {{ $ticket->is_checked_in ? __('app.festival_checked_in') : __('app.festival_not_checked_in') }}</span>
                            @if ($ticket->status === \App\Enums\FestivalTicketStatus::Valid)
                                @if ($ticket->is_checked_in)
                                    <x-ui.button type="button" variant="secondary" size="sm" data-door-checkout data-checkout-url="{{ route('dashboard.accounts.festivals.scanner.check-out', [$account, $festivalEdition, $ticket]) }}">{{ __('app.festival_check_out') }}</x-ui.button>
                                @else
                                    <x-ui.button type="button" variant="secondary" size="sm" data-door-checkin data-ticket-code="{{ $ticket->code }}">{{ __('app.festival_check_in') }}</x-ui.button>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            {{ $tickets->links() }}
        </section>
    </div>
</x-festivals.staff.workspace>
@endsection
