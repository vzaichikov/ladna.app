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
        data-camera-automatic="{{ __('app.festival_camera_automatic') }}"
        data-camera-name-template="{{ __('app.festival_camera_name', ['number' => '__NUMBER__']) }}"
        data-request-error="{{ __('app.festival_scanner_request_failed') }}"
        data-check-out-reason="{{ __('app.festival_check_out_reason_prompt') }}"
        data-torch-enable="{{ __('app.festival_torch_enable') }}"
        data-torch-disable="{{ __('app.festival_torch_disable') }}"
    >
        <x-ui.page-header :title="__('app.festival_scanner')" :copy="__('app.festival_scanner_online_only')">
            @if (auth()->user()?->can('doorStaff', $account) || ($workspacePermissions['event_festival_staff'] ?? false))
                <x-slot:actions>
                    <x-ui.button :href="route('dashboard.accounts.festivals.attendance', [$account, $festivalEdition])" variant="secondary"><x-ui.icon name="monitor" class="h-4 w-4" />{{ __('app.festival_entrance_monitor') }}</x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.page-header>

        <section class="grid gap-5 lg:grid-cols-[1.2fr_.8fr]">
            <div class="overflow-hidden rounded-2xl border border-stone-200 bg-slate-950 shadow-crm">
                <video data-scanner-video class="aspect-[3/4] w-full object-cover sm:aspect-video" playsinline muted></video>
                <div class="grid gap-3 bg-white p-4 sm:grid-cols-[1fr_auto_auto]">
                    <select data-scanner-camera class="crm-field mt-0"><option>{{ __('app.festival_camera_loading') }}</option></select>
                    <x-ui.button type="button" data-scanner-start>{{ __('app.festival_scanner_start') }}</x-ui.button>
                    <x-ui.button type="button" data-scanner-torch variant="secondary" class="hidden" aria-pressed="false">{{ __('app.festival_torch_enable') }}</x-ui.button>
                </div>
            </div>
            <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <h2 class="text-xl font-semibold">{{ __('app.festival_manual_code') }}</h2>
                <form data-scanner-manual class="mt-4 space-y-3">
                    <input name="code" required autocomplete="off" class="crm-field mt-0 font-mono uppercase tracking-wide" placeholder="FST-… / FSP-…">
                    <x-ui.button type="submit" class="w-full">{{ __('app.festival_check_in') }}</x-ui.button>
                </form>
                <div data-scanner-result class="mt-5 hidden rounded-xl p-4 text-sm font-semibold" role="status"></div>
            </div>
        </section>

        @if (isset($entranceTools))
            <x-ui.entrance-tools
                :search-url="$entranceTools['search_url']"
                :cash-sale-url="$entranceTools['cash_sale_url']"
                :card-sale-url="$entranceTools['card_sale_url']"
                :ticket-types="$entranceTools['ticket_types']"
                :payment-providers="$entranceTools['payment_providers']"
                :currency="$festivalEdition->currency"
                :can-sell="$entranceTools['can_sell']"
                :search-label="__('app.festival_entrance_search_people')"
                :search-hint="__('app.festival_entrance_search_hint')"
                :search-placeholder="__('app.festival_entrance_search_placeholder')"
                :no-people-label="__('app.festival_entrance_no_people')"
            />
        @endif

        <x-ui.ticket-scanner-modal />
    </div>
</x-festivals.staff.workspace>
@endsection
