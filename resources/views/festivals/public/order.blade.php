@extends('layouts.public')

@section('title', __('app.festival_tickets').' - '.$order->edition->title)
@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
@php
    $edition = $order->edition;
    $festivalCancelled = $edition->cancelled_at !== null
        || $edition->status === \App\Enums\FestivalEditionStatus::Archived;
    $isPaidSuccess = $order->status === \App\Enums\FestivalTicketOrderStatus::Paid && ! $festivalCancelled;
    $validTickets = $isPaidSuccess
        ? $order->tickets
            ->where('status', \App\Enums\FestivalTicketStatus::Valid)
            ->filter(fn ($ticket) => $ticket->admissionType?->delivery_mode === \App\Enums\FestivalAdmissionDeliveryMode::Venue)
        : collect();
    $validOnlineTickets = $isPaidSuccess
        ? $order->tickets
            ->where('status', \App\Enums\FestivalTicketStatus::Valid)
            ->filter(fn ($ticket) => $ticket->admissionType?->delivery_mode === \App\Enums\FestivalAdmissionDeliveryMode::OnlineStream
                && $ticket->streamEntitlement !== null)
        : collect();
    $hasValidOnlineTicket = $validOnlineTickets->isNotEmpty();
    $ticketsReady = $validTickets->isNotEmpty();
    $pdfFilename = 'festival-tickets-'.$order->order_id.'.pdf';
    $venue = collect([$edition->venue_name, $edition->venue_address])->filter()->join(' · ');
    $date = $edition->starts_at
        ? $edition->starts_at->timezone($edition->timezone)->format('d.m.Y H:i')
        : null;
    $statusClass = $festivalCancelled
        ? 'bg-rose-100 text-rose-800'
        : match ($order->status) {
            \App\Enums\FestivalTicketOrderStatus::Paid => 'bg-emerald-100 text-emerald-800',
            \App\Enums\FestivalTicketOrderStatus::Pending => 'bg-amber-100 text-amber-800',
            default => 'bg-rose-100 text-rose-800',
        };
@endphp
<main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8 text-slate-950 sm:px-8" data-ticket-order-return>
    <section class="mx-auto max-w-6xl" @if ($ticketsReady) data-print-section data-ticket-print @endif>
        <div data-ticket-screen>
            <x-ui.public-studio-header :account="$account" class="mb-6" />

            @if (session('status'))
                <div class="mb-6 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900" role="status">{{ session('status') }}</div>
            @endif

            <header class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-brand-700">{{ $account->name }}</p>
                        <h1 class="mt-2 text-3xl font-semibold sm:text-4xl">{{ $edition->title }}</h1>
                        @if ($date || $venue !== '')
                            <p class="mt-3 text-slate-500">{{ collect([$date, $venue])->filter()->join(' · ') }}</p>
                        @endif
                    </div>
                    <span class="inline-flex self-start rounded-full px-3 py-1 text-sm font-semibold {{ $statusClass }}">{{ __('app.festival_order_'.$order->status->value) }}</span>
                </div>

                @if ($festivalCancelled)
                    <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-rose-950">
                        <h2 class="font-semibold">{{ __('app.festival_order_cancelled') }}</h2>
                        <p class="mt-2 text-sm leading-6">{{ __('app.festival_order_state_cancelled') }}</p>
                    </div>
                @elseif ($ticketsReady)
                    <div class="mt-6 rounded-2xl bg-emerald-50 p-5 text-emerald-950">
                        <h2 class="text-2xl font-semibold">{{ __('app.festival_order_thank_you') }}</h2>
                        <p class="mt-2 text-sm leading-6">{{ __('app.festival_order_tickets_emailed', ['address' => $order->buyer_email]) }}</p>
                    </div>
                @elseif ($hasValidOnlineTicket)
                    <div class="mt-6 rounded-2xl bg-emerald-50 p-5 text-emerald-950">
                        <h2 class="text-2xl font-semibold">{{ __('app.festival_order_thank_you') }}</h2>
                        <p class="mt-2 text-sm leading-6">{{ __('app.festival_order_online_ticket_ready') }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach ($validOnlineTickets as $ticket)
                                <div class="w-full rounded-xl border border-emerald-200 bg-white/70 p-3">
                                    <p class="mb-3 text-sm font-semibold">{{ $ticket->orderItem?->admission_name ?? $ticket->admissionType?->name }}</p>
                                    <div class="flex flex-wrap gap-2">
                                        <x-ui.button :href="route('public.festival-orders.stream.watch', [$account->slug, $order->access_token_encrypted, $ticket->streamEntitlement])" variant="success" size="sm">
                                            <x-ui.icon name="play" class="h-4 w-4" />
                                            {{ __('app.festival_watch_stream') }}
                                        </x-ui.button>
                                        <form method="POST" action="{{ route('public.festival-orders.stream.release', [$account->slug, $order->access_token_encrypted, $ticket->streamEntitlement]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.festival_stream_release_devices') }}</x-ui.button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @elseif ($order->status === \App\Enums\FestivalTicketOrderStatus::Pending)
                    <div
                        class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-950"
                        data-ticket-order-poll
                        data-status-url="{{ $statusUrl }}"
                        data-refresh-url="{{ request()->fullUrl() }}"
                        data-timeout-message="{{ __('app.festival_order_confirmation_taking_longer') }}"
                    >
                        <div class="flex items-start gap-3">
                            <x-ui.icon name="loader-circle" class="mt-0.5 h-5 w-5 shrink-0 animate-spin" />
                            <div>
                                <h2 class="font-semibold">{{ __('app.festival_order_confirming_payment') }}</h2>
                                <p class="mt-1 text-sm leading-6" data-ticket-order-poll-message aria-live="polite">{{ __('app.festival_order_confirming_payment_help') }}</p>
                            </div>
                        </div>
                        <div class="mt-4 hidden" data-ticket-order-poll-timeout>
                            <x-ui.button :href="request()->fullUrl()" variant="secondary" size="sm">
                                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                {{ __('app.festival_order_refresh') }}
                            </x-ui.button>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if ($paymentUrl)
                                <x-ui.button :href="$paymentUrl" variant="success" size="sm">
                                    <x-ui.icon name="credit-card" class="h-4 w-4" />
                                    {{ __('app.festival_monopay_resume_payment') }}
                                </x-ui.button>
                            @endif
                            <x-ui.button :href="route('public.festivals.show', [$account->slug, $edition->slug]).'#festival-admission'" variant="secondary" size="sm" data-festival-order-return-to-tickets>
                                <x-ui.icon name="arrow-left" class="h-4 w-4" />
                                {{ __('app.festival_order_return_to_tickets') }}
                            </x-ui.button>
                        </div>
                    </div>
                @elseif ($order->status === \App\Enums\FestivalTicketOrderStatus::Paid)
                    <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-950">
                        <h2 class="font-semibold">{{ __('app.festival_order_tickets_processing') }}</h2>
                        <p class="mt-2 text-sm leading-6">{{ __('app.festival_order_tickets_processing_help') }}</p>
                    </div>
                @else
                    <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-rose-950">
                        <h2 class="font-semibold">{{ __('app.festival_order_'.$order->status->value) }}</h2>
                        <p class="mt-2 text-sm leading-6">{{ __('app.festival_order_state_'.$order->status->value) }}</p>
                    </div>
                @endif
            </header>

            @if ($ticketsReady)
                <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-7">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-2xl font-semibold">{{ __('app.festival_order_your_tickets') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('app.festival_order_keep_tickets_available') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2" data-print-screen-only>
                            <x-ui.button type="button" variant="success" data-ticket-pdf-share data-pdf-url="{{ $pdfUrl }}" data-pdf-filename="{{ $pdfFilename }}" data-share-title="{{ $edition->title }}">
                                <x-ui.icon name="share-2" class="h-4 w-4" />
                                <span data-ticket-share-label data-default-label="{{ __('app.festival_order_share_pdf') }}" data-loading-label="{{ __('app.festival_order_preparing_pdf') }}">{{ __('app.festival_order_share_pdf') }}</span>
                            </x-ui.button>
                            <x-ui.button :href="$pdfUrl" variant="secondary" download data-ticket-pdf-download>
                                <x-ui.icon name="download" class="h-4 w-4" />
                                {{ __('app.festival_order_download_pdf') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="secondary" data-print-button>
                                <x-ui.icon name="printer" class="h-4 w-4" />
                                {{ __('app.festival_order_print_or_save') }}
                            </x-ui.button>
                        </div>
                    </div>
                    <p class="mt-3 hidden text-sm font-semibold text-rose-700" data-ticket-share-error aria-live="polite">{{ __('app.festival_order_share_pdf_failed') }}</p>

                    <div class="mt-6 overflow-hidden rounded-xl border border-stone-200">
                        <table class="block w-full divide-y divide-stone-200 text-sm sm:table sm:text-left">
                            <thead class="hidden bg-slate-50 text-slate-600 sm:table-header-group">
                                <tr>
                                    <th scope="col" class="px-4 py-3 font-semibold">{{ __('app.festival_ticket_type') }}</th>
                                    <th scope="col" class="px-4 py-3 font-semibold">{{ __('app.festival_ticket_qr') }}</th>
                                    <th scope="col" class="px-4 py-3 font-semibold">{{ __('app.event_ticket_code') }}</th>
                                </tr>
                            </thead>
                            <tbody class="grid divide-y divide-stone-100 bg-white sm:table-row-group">
                                @foreach ($validTickets as $ticket)
                                    <tr class="grid justify-items-center gap-3 px-4 py-5 text-center sm:table-row sm:text-left" data-festival-ticket-row>
                                        <td class="block min-w-0 p-0 font-semibold sm:table-cell sm:min-w-48 sm:px-4 sm:py-4 sm:align-middle">
                                            <span class="mb-1 block text-xs font-semibold text-slate-500 sm:hidden">{{ __('app.festival_ticket_type') }}</span>
                                            {{ $ticket->orderItem?->admission_name ?? $ticket->admissionType?->name }}
                                        </td>
                                        <td class="block p-0 sm:table-cell sm:px-4 sm:py-4 sm:align-middle">
                                            <span class="mb-2 block text-xs font-semibold text-slate-500 sm:hidden">{{ __('app.festival_ticket_qr') }}</span>
                                            <img src="{{ $qrCodes[$ticket->id] }}" alt="{{ __('app.festival_ticket_qr') }}" class="h-36 w-36 max-w-full sm:max-w-none">
                                        </td>
                                        <td class="block p-0 font-mono text-base font-semibold whitespace-nowrap sm:table-cell sm:px-4 sm:py-4 sm:align-middle">
                                            <span class="mb-1 block font-sans text-xs font-semibold text-slate-500 sm:hidden">{{ __('app.event_ticket_code') }}</span>
                                            {{ $ticket->code }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        @if ($ticketsReady)
            <div class="hidden" data-ticket-print-pages>
                @foreach ($validTickets as $ticket)
                    <article data-ticket-print-page>
                        <p class="text-sm font-semibold">{{ $account->name }}</p>
                        <h1 class="mt-3 text-3xl font-semibold">{{ $edition->title }}</h1>
                        @if ($date)<p class="mt-3 text-base">{{ $date }}</p>@endif
                        @if ($venue !== '')<p class="mt-1 text-sm">{{ $venue }}</p>@endif
                        <p class="mt-8 text-lg font-semibold">{{ $ticket->orderItem?->admission_name ?? $ticket->admissionType?->name }}</p>
                        <img src="{{ $qrCodes[$ticket->id] }}" alt="{{ __('app.festival_ticket_qr') }}" data-ticket-print-qr>
                        <p class="mt-5 font-mono text-2xl font-semibold">{{ $ticket->code }}</p>
                        <p class="mt-3 text-sm">{{ __('app.festival_ticket_present_at_entrance') }}</p>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</main>
@endsection
