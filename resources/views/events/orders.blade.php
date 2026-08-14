@extends('layouts.app')

@section('title', __('app.event_orders').' - '.$event->title)

@section('content')
<div class="space-y-6">
    <x-ui.page-header :title="__('app.event_orders')" :copy="__('app.event_orders_help')" />
    <x-ui.event-navigation :account="$account" :event="$event" active="orders" />
    @if ($urgentRefundsCount > 0)
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 font-semibold text-rose-900">{{ trans_choice('app.event_urgent_refunds', $urgentRefundsCount, ['count' => $urgentRefundsCount]) }}</div>
    @endif
    <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">{{ __('app.event_order') }}</th><th class="px-5 py-3">{{ __('app.event_buyer') }}</th><th class="px-5 py-3">{{ __('app.event_tickets') }}</th><th class="px-5 py-3">{{ __('app.amount') }}</th><th class="px-5 py-3">{{ __('app.status') }}</th><th class="px-5 py-3"></th></tr></thead>
                <tbody class="divide-y divide-stone-100">
                @forelse ($orders as $order)
                    <tr>
                        <td class="px-5 py-4 font-mono text-xs">{{ $order->order_id }}</td>
                        <td class="px-5 py-4">
                            @php($latestEmailDelivery = $order->emailDeliveries->first())
                            <strong>{{ $order->buyer_name }}</strong>
                            @if (filled($order->buyer_email) || filled($order->buyer_phone))
                                <span class="block text-xs text-slate-500">
                                    @if (filled($order->buyer_email)){{ $order->buyer_email }}@endif
                                    @if (filled($order->buyer_email) && filled($order->buyer_phone)) · @endif
                                    @if (filled($order->buyer_phone)){{ $order->buyer_phone }}@endif
                                </span>
                            @endif
                            <span class="mt-1 block text-xs text-slate-500">{{ __('app.event_delivery_audit') }}: {{ $order->emailDeliveries->count() }}@if ($latestEmailDelivery) · {{ __($latestEmailDelivery->status->labelKey()) }} · {{ $latestEmailDelivery->created_at->format('d.m H:i') }}@endif</span>
                        </td>
                        <td class="px-5 py-4">
                            <details>
                                <summary class="cursor-pointer font-semibold">{{ $order->items->sum('quantity') }}</summary>
                                <div class="mt-3 space-y-3">
                                    @foreach ($order->tickets as $ticket)
                                        <div class="rounded-lg bg-slate-50 p-3">
                                            <span class="font-mono text-xs">{{ $ticket->code }}</span>
                                            <span class="ml-2 text-xs">{{ __('app.event_ticket_status_'.$ticket->status->value) }}</span>
                                            @if ($ticket->status === \App\Enums\EventTicketStatus::Valid)
                                                <form method="POST" action="{{ route('dashboard.accounts.events.orders.tickets.void', [$account, $event, $order, $ticket]) }}" class="mt-2 flex gap-2">
                                                    @csrf
                                                    <input name="reason" required maxlength="2000" placeholder="{{ __('app.reason') }}" class="crm-field mt-0 min-w-44 text-xs">
                                                    <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.event_void_ticket') }}</x-ui.button>
                                                </form>
                                            @elseif ($ticket->void_reason)
                                                <p class="mt-1 text-xs text-slate-500">{{ $ticket->void_reason }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        </td>
                        <td class="px-5 py-4">{{ \App\Support\MoneyFormatter::format($order->amount_cents, $order->currency) }}</td>
                        <td class="px-5 py-4">
                            <span class="{{ in_array($order->status, [\App\Enums\EventOrderStatus::Paid, \App\Enums\EventOrderStatus::Refunded], true) ? 'crm-status-active' : (in_array($order->status, [\App\Enums\EventOrderStatus::RefundRequired, \App\Enums\EventOrderStatus::PaidRequiresRefund, \App\Enums\EventOrderStatus::Failed], true) ? 'crm-status-danger' : 'crm-status-muted') }}">
                                {{ __('app.event_order_status_'.$order->status->value) }}
                            </span>
                            @if ($order->refund_reason)<p class="mt-1 text-xs text-slate-500">{{ $order->refund_reason }}</p>@endif
                        </td>
                        <td class="px-5 py-4">
                            <div class="space-y-2">
                                @if ($order->tickets->isNotEmpty() && filled($order->buyer_email))
                                    <form method="POST" action="{{ route('dashboard.accounts.events.orders.resend', [$account, $event, $order]) }}">@csrf<x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.event_resend_tickets') }}</x-ui.button></form>
                                @endif
                                @if (in_array($order->status, [\App\Enums\EventOrderStatus::Paid, \App\Enums\EventOrderStatus::RefundRequired, \App\Enums\EventOrderStatus::PaidRequiresRefund], true) && $order->amount_cents > 0)
                                    <form method="POST" action="{{ route('dashboard.accounts.events.orders.refund', [$account, $event, $order]) }}" class="space-y-2">
                                        @csrf
                                        <input name="reason" required maxlength="2000" placeholder="{{ __('app.event_external_refund_reason') }}" class="crm-field mt-0 w-52 text-xs">
                                        <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.event_mark_refunded') }}</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-10 text-center text-sm text-slate-500">{{ __('app.event_orders_empty') }}</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $orders->links() }}
</div>
@endsection
