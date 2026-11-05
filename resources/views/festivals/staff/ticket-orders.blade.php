@extends('layouts.app')
@section('title', __('app.festival_ticket_orders_tab').' - '.$edition->title)
@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_ticket_orders_tab')" :copy="__('app.festival_ticket_orders_copy')" />

    <p class="text-sm text-slate-600">{{ __('app.festival_admission_revenue') }}: @foreach ($admissionReport['revenue_by_currency'] as $currency => $amountCents)<strong>{{ \App\Support\MoneyFormatter::format($amountCents, $currency) }}</strong> @endforeach</p>
            <x-ui.filter-bar :action="route('dashboard.accounts.festivals.ticket-orders.index', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.ticket-orders.index', [$account, $edition])" class="xl:grid-cols-4">
                <label><span class="crm-label">{{ __('app.search') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_order_search_placeholder') }}"></label>
                <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach(\App\Enums\FestivalTicketOrderStatus::cases() as $status)<option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ __('app.festival_order_'.$status->value) }}</option>@endforeach</select></label>
                <label><span class="crm-label">{{ __('app.festival_ticket_source') }}</span><select name="source" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach(\App\Enums\FestivalTicketOrderSource::cases() as $source)<option value="{{ $source->value }}" @selected($filters['source'] === $source->value)>{{ __('app.festival_ticket_source_'.$source->value) }}</option>@endforeach</select></label>
                <label><span class="crm-label">{{ __('app.payment_provider') }}</span><select name="provider" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach($orderProviderOptions as $provider)<option value="{{ $provider }}" @selected($filters['provider'] === $provider)>{{ config('integrations.providers.'.$provider.'.label', $provider) }}</option>@endforeach</select></label>
            </x-ui.filter-bar>

            <div class="space-y-4" data-festival-ticket-orders>
                @forelse ($orders as $order)
                    @php
                        $orderStatusClass = match ($order->status) {
                            \App\Enums\FestivalTicketOrderStatus::Paid => 'crm-status-active',
                            \App\Enums\FestivalTicketOrderStatus::Pending => 'crm-status-scheduled',
                            \App\Enums\FestivalTicketOrderStatus::Failed, \App\Enums\FestivalTicketOrderStatus::PaidRequiresRefund => 'crm-status-danger',
                            default => 'crm-status-muted',
                        };
                    @endphp
                    <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm" data-festival-order-row="{{ $order->id }}">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="break-all font-mono text-sm text-slate-950">{{ $order->order_id }}</strong>
                                    <span class="{{ $orderStatusClass }}">{{ __('app.festival_order_'.$order->status->value) }}</span>
                                    <span class="crm-status-muted">{{ __('app.festival_ticket_source_'.$order->source->value) }}</span>
                                </div>
                                <h3 class="mt-3 text-lg font-semibold text-slate-950">{{ $order->buyer_name }}</h3>
                                <p class="mt-1 break-words text-sm text-slate-600">{{ $order->buyer_email }}@if($order->buyer_phone) · {{ $order->buyer_phone }}@endif</p>
                                <p class="mt-2 text-xs text-slate-500">{{ $order->created_at->timezone($edition->timezone)->format('d.m.Y H:i') }}</p>
                            </div>
                            <div class="lg:text-right">
                                <strong class="text-lg text-slate-950">{{ \App\Support\MoneyFormatter::format($order->amount_cents, $order->currency) }}</strong>
                                @if ($order->discount_cents > 0)<p class="mt-1 text-xs font-semibold text-emerald-700">{{ \App\Support\MoneyFormatter::format($order->subtotal_cents, $order->currency) }} · {{ $order->promo_code }} −{{ \App\Support\MoneyFormatter::format($order->discount_cents, $order->currency) }}</p>@endif
                                <p class="mt-1 text-xs text-slate-500">{{ config('integrations.providers.'.$order->provider.'.label', $order->provider ?: '—') }}</p>
                            </div>
                        </div>

                        <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('app.festival_order_items') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $order->items->map(fn ($item) => $item->admission_name.' × '.$item->quantity)->join(', ') }}</dd><span class="mt-1 block text-xs text-slate-500">{{ __('app.festival_tickets_issued') }}: {{ $order->tickets_count }}</span></div>
                            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('app.ticket_payment_invoice_deadline') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $order->payment_expires_at?->timezone($edition->timezone)->format('d.m.Y H:i:s') ?? '—' }}</dd></div>
                            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('app.ticket_inventory_reservation_deadline') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $order->expires_at?->timezone($edition->timezone)->format('d.m.Y H:i:s') ?? '—' }}</dd></div>
                            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('app.festival_payment_identifiers') }}</dt><dd class="mt-1 break-all font-mono text-xs text-slate-900">{{ $order->gateway_invoice_id ?: '—' }}</dd><span class="mt-1 block break-all font-mono text-xs text-slate-500">{{ $order->gateway_payment_id ?: '—' }}</span></div>
                        </dl>

                        @if ($order->failure_reason)
                            <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{{ $order->failure_reason }}</p>
                        @endif
                    </article>
                @empty
                    <x-ui.empty-state icon="receipt">{{ __('app.festival_ticket_orders_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
            <div>{{ $orders->links() }}</div>
</x-festivals.staff.workspace>
@endsection
