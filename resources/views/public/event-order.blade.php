@extends('layouts.public')

@section('title', __('app.event_order').' - '.$order->event->title)
@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-4xl bg-canvas px-5 pb-8" />
@endsection

@section('content')
<main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8 text-slate-950">
    <section class="mx-auto max-w-4xl">
        <x-ui.public-studio-header :account="$account" class="mb-6" />

        <header class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8">
            <p class="text-sm font-semibold text-brand-700">{{ $account->name }}</p>
            <h1 class="mt-2 text-3xl font-semibold">{{ $order->event->title }}</h1>
            <p class="mt-3 text-slate-500">{{ $order->event->starts_at->timezone($order->event->timezone)->format('d.m.Y H:i') }}</p>
            <span class="mt-4 inline-flex rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ __('app.event_order_status_'.$order->status->value) }}</span>
            @if ($order->event->status === \App\Enums\EventStatus::Cancelled)
                <p class="mt-4 rounded-xl bg-rose-50 p-4 text-sm font-semibold text-rose-900">{{ __('app.event_cancelled_sales_closed') }}</p>
            @endif
        </header>

        @if ($order->tickets->isNotEmpty())
            <div class="mt-6 grid gap-5 sm:grid-cols-2">
                @foreach ($order->tickets as $ticket)
                    <article class="rounded-2xl border border-stone-200 bg-white p-5 text-center shadow-crm">
                        <p class="text-sm text-slate-500">{{ $ticket->ticketType?->name }}</p>
                        @if ($ticket->status === \App\Enums\EventTicketStatus::Valid && $order->event->status !== \App\Enums\EventStatus::Cancelled)
                            <img src="{{ $ticketQrCodes[$ticket->id] }}" alt="{{ __('app.event_ticket_qr') }}" class="mx-auto mt-3 w-full max-w-64">
                        @else
                            <span class="mt-4 inline-flex rounded-full bg-rose-100 px-3 py-1 text-sm font-semibold text-rose-800">{{ __('app.event_ticket_status_'.$ticket->status->value) }}</span>
                        @endif
                        <p class="mt-3 font-mono text-lg font-semibold">{{ $ticket->code }}</p>
                        @if ($ticket->status === \App\Enums\EventTicketStatus::Valid && $order->event->status !== \App\Enums\EventStatus::Cancelled)
                            <p class="mt-1 text-xs text-slate-500">{{ __('app.event_ticket_present_at_door') }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @else
            <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-6 text-amber-900">{{ __('app.event_order_waiting_for_payment') }}</div>
        @endif
    </section>
</main>
@endsection
