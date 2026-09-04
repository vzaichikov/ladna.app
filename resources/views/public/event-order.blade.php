@extends('layouts.public')

@section('title', __('app.event_order').' - '.$order->event->title)
@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
@php
    $event = $order->event;
    $eventCancelled = $event->status === \App\Enums\EventStatus::Cancelled;
    $isPaidSuccess = $order->status === \App\Enums\EventOrderStatus::Paid
        && $event->status === \App\Enums\EventStatus::Published;
    $validTickets = $isPaidSuccess
        ? $order->tickets->where('status', \App\Enums\EventTicketStatus::Valid)
        : collect();
    $ticketsReady = $validTickets->isNotEmpty();
    $resolvedStatusUrl = $statusUrl ?? route('public.event-orders.status', [$account->slug, $order->access_token_encrypted]);
    $resolvedPdfUrl = $pdfUrl ?? route('public.event-orders.pdf', [$account->slug, $order->access_token_encrypted]);
    $pdfFilename = 'event-tickets-'.$order->order_id.'.pdf';
    $venue = $event->venue_kind->value === 'studio'
        ? collect([$event->location?->name, $event->location?->address, $event->rooms->pluck('name')->join(', ')])->filter()->join(' · ')
        : collect([$event->external_venue_name, $event->external_address])->filter()->join(' · ');
    $statusClass = $eventCancelled
        ? 'bg-rose-100 text-rose-800'
        : match ($order->status) {
            \App\Enums\EventOrderStatus::Paid => $ticketsReady ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800',
            \App\Enums\EventOrderStatus::Pending => 'bg-amber-100 text-amber-800',
            default => 'bg-rose-100 text-rose-800',
        };
@endphp
<main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8 text-slate-950 sm:px-8" data-event-order-return>
    <section class="mx-auto max-w-6xl" @if ($ticketsReady) data-print-section data-event-ticket-print @endif>
        <div data-event-ticket-screen>
            <x-ui.public-studio-header :account="$account" class="mb-6" />

            <header class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-brand-700">{{ $account->name }}</p>
                        <h1 class="mt-2 text-3xl font-semibold sm:text-4xl">{{ $event->title }}</h1>
                        <p class="mt-3 text-slate-500">{{ $event->starts_at->timezone($event->timezone)->format('d.m.Y H:i') }} · {{ $venue }}</p>
                    </div>
                    <span class="inline-flex self-start rounded-full px-3 py-1 text-sm font-semibold {{ $statusClass }}">{{ __('app.event_order_status_'.$order->status->value) }}</span>
                </div>

                @if ($eventCancelled)
                    <p class="mt-5 rounded-xl bg-rose-50 p-4 text-sm font-semibold text-rose-900">{{ __('app.event_cancelled_sales_closed') }}</p>
                @elseif ($ticketsReady)
                    <div class="mt-6 rounded-2xl bg-emerald-50 p-5 text-emerald-950">
                        <h2 class="text-2xl font-semibold">{{ __('app.event_order_thank_you') }}</h2>
                        <p class="mt-2 text-sm leading-6">{{ __('app.event_order_tickets_emailed', ['email' => $order->buyer_email]) }}</p>
                    </div>
                @elseif ($order->status === \App\Enums\EventOrderStatus::Pending)
                    <div
                        class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-950"
                        data-event-order-poll
                        data-status-url="{{ $resolvedStatusUrl }}"
                        data-refresh-url="{{ request()->fullUrl() }}"
                        data-timeout-message="{{ __('app.event_order_confirmation_taking_longer') }}"
                    >
                        <div class="flex items-start gap-3">
                            <x-ui.icon name="loader-circle" class="mt-0.5 h-5 w-5 shrink-0 animate-spin" />
                            <div>
                                <h2 class="font-semibold">{{ __('app.event_order_confirming_payment') }}</h2>
                                <p class="mt-1 text-sm leading-6" data-event-order-poll-message aria-live="polite">{{ __('app.event_order_confirming_payment_help') }}</p>
                            </div>
                        </div>
                        <div class="mt-4 hidden" data-event-order-poll-timeout>
                            <x-ui.button :href="request()->fullUrl()" variant="secondary" size="sm">
                                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                {{ __('app.event_order_refresh') }}
                            </x-ui.button>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if ($paymentUrl ?? null)
                                <x-ui.button :href="$paymentUrl" variant="success" size="sm">
                                    <x-ui.icon name="credit-card" class="h-4 w-4" />
                                    {{ __('app.event_monopay_resume_payment') }}
                                </x-ui.button>
                            @endif
                            <x-ui.button :href="route('public.events.show', [$account->slug, $event->slug])" variant="secondary" size="sm" data-event-order-return-to-event>
                                <x-ui.icon name="arrow-left" class="h-4 w-4" />
                                {{ __('app.event_order_return_to_event') }}
                            </x-ui.button>
                        </div>
                    </div>
                @elseif ($order->status === \App\Enums\EventOrderStatus::Paid)
                    <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-950">
                        <h2 class="font-semibold">{{ __('app.event_order_tickets_processing') }}</h2>
                        <p class="mt-2 text-sm leading-6">{{ __('app.event_order_tickets_processing_help') }}</p>
                    </div>
                @else
                    <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-rose-950">
                        <h2 class="font-semibold">{{ __('app.event_order_status_'.$order->status->value) }}</h2>
                        <p class="mt-2 text-sm leading-6">{{ __('app.event_order_state_'.$order->status->value) }}</p>
                    </div>
                @endif
            </header>

            @if ((int) ($order->discount_cents ?? 0) > 0)
                <dl class="mt-6 grid grid-cols-2 gap-3 rounded-2xl border border-stone-200 bg-white p-5 text-sm shadow-crm sm:p-6">
                    <div>
                        <dt class="text-slate-500">{{ __('app.subtotal') }}</dt>
                        <dd class="mt-1 font-semibold tabular-nums">{{ \App\Support\MoneyFormatter::format($order->subtotal_cents, $order->currency) }}</dd>
                    </div>
                    <div class="text-right">
                        <dt class="text-slate-500">{{ __('app.promo_code_discount') }} · {{ $order->promo_code }}</dt>
                        <dd class="mt-1 font-semibold tabular-nums text-emerald-700">−{{ \App\Support\MoneyFormatter::format($order->discount_cents, $order->currency) }}</dd>
                    </div>
                    <div class="col-span-2 border-t border-stone-200 pt-3 text-right">
                        <dt class="text-slate-500">{{ __('app.total') }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums">{{ \App\Support\MoneyFormatter::format($order->amount_cents, $order->currency) }}</dd>
                    </div>
                </dl>
            @endif

            @if ($ticketsReady)
                <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-7">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-2xl font-semibold">{{ __('app.event_order_your_tickets') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('app.event_order_keep_tickets_available') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2" data-print-screen-only>
                            <x-ui.button
                                type="button"
                                variant="success"
                                data-event-ticket-pdf-share
                                data-pdf-url="{{ $resolvedPdfUrl }}"
                                data-pdf-filename="{{ $pdfFilename }}"
                                data-share-title="{{ $event->title }}"
                            >
                                <x-ui.icon name="share-2" class="h-4 w-4" />
                                <span data-event-ticket-share-label data-default-label="{{ __('app.event_order_share_pdf') }}" data-loading-label="{{ __('app.event_order_preparing_pdf') }}">{{ __('app.event_order_share_pdf') }}</span>
                            </x-ui.button>
                            <x-ui.button :href="$resolvedPdfUrl" variant="secondary" download data-event-ticket-pdf-download>
                                <x-ui.icon name="download" class="h-4 w-4" />
                                {{ __('app.event_order_download_pdf') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="secondary" data-print-button>
                                <x-ui.icon name="printer" class="h-4 w-4" />
                                {{ __('app.event_order_print_or_save') }}
                            </x-ui.button>
                        </div>
                    </div>
                    <p class="mt-3 hidden text-sm font-semibold text-rose-700" data-event-ticket-share-error aria-live="polite">{{ __('app.event_order_share_pdf_failed') }}</p>

                    <div class="mt-6 overflow-hidden rounded-xl border border-stone-200">
                        <table class="block w-full divide-y divide-stone-200 text-sm sm:table sm:text-left">
                            <thead class="hidden bg-slate-50 text-slate-600 sm:table-header-group">
                                <tr>
                                    <th scope="col" class="px-4 py-3 font-semibold">{{ __('app.event_ticket_option') }}</th>
                                    <th scope="col" class="px-4 py-3 font-semibold">{{ __('app.event_ticket_qr') }}</th>
                                    <th scope="col" class="px-4 py-3 font-semibold">{{ __('app.event_ticket_code') }}</th>
                                </tr>
                            </thead>
                            <tbody class="grid divide-y divide-stone-100 bg-white sm:table-row-group">
                                @foreach ($validTickets as $ticket)
                                    @php($ticketItem = $order->items->firstWhere('id', $ticket->event_order_item_id))
                                    <tr class="grid justify-items-center gap-3 px-4 py-5 text-center sm:table-row sm:text-left" data-event-ticket-row>
                                        <td class="block min-w-0 p-0 font-semibold sm:table-cell sm:min-w-48 sm:px-4 sm:py-4 sm:align-middle">
                                            <span class="mb-1 block text-xs font-semibold text-slate-500 sm:hidden">{{ __('app.event_ticket_option') }}</span>
                                            {{ $ticketItem?->ticket_type_name ?? $ticket->ticketType?->name }}
                                        </td>
                                        <td class="block p-0 sm:table-cell sm:px-4 sm:py-4 sm:align-middle">
                                            <span class="mb-2 block text-xs font-semibold text-slate-500 sm:hidden">{{ __('app.event_ticket_qr') }}</span>
                                            <img src="{{ $ticketQrCodes[$ticket->id] }}" alt="{{ __('app.event_ticket_qr') }}" class="h-36 w-36 max-w-full sm:max-w-none">
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
            <div class="hidden" data-event-ticket-print-pages>
                @foreach ($validTickets as $ticket)
                    @php($ticketItem = $order->items->firstWhere('id', $ticket->event_order_item_id))
                    <article data-event-ticket-print-page>
                        <p class="text-sm font-semibold">{{ $account->name }}</p>
                        <h1 class="mt-3 text-3xl font-semibold">{{ $event->title }}</h1>
                        <p class="mt-3 text-base">{{ $event->starts_at->timezone($event->timezone)->format('d.m.Y H:i') }}</p>
                        <p class="mt-1 text-sm">{{ $venue }}</p>
                        <p class="mt-8 text-lg font-semibold">{{ $ticketItem?->ticket_type_name ?? $ticket->ticketType?->name }}</p>
                        <img src="{{ $ticketQrCodes[$ticket->id] }}" alt="{{ __('app.event_ticket_qr') }}" data-event-ticket-print-qr>
                        <p class="mt-5 font-mono text-2xl font-semibold">{{ $ticket->code }}</p>
                        <p class="mt-3 text-sm">{{ __('app.event_ticket_present_at_door') }}</p>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</main>
@endsection
