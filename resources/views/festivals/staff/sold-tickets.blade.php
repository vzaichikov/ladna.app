@extends('layouts.app')
@section('title', __('app.festival_bought_tickets_tab').' - '.$edition->title)
@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_bought_tickets_tab')" :copy="__('app.festival_bought_tickets_copy')">
        <x-slot:actions><x-ui.button :href="route('dashboard.accounts.festivals.tickets.issue', [$account, $edition])">{{ __('app.festival_issue_tickets') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>
            @if ($refundRequiredOrders->isNotEmpty())
                <section class="rounded-2xl border border-rose-200 bg-rose-50 p-5">
                    <h3 class="font-semibold text-rose-950">{{ __('app.festival_refund_pending') }}</h3>
                    <div class="mt-4 space-y-3">
                        @foreach ($refundRequiredOrders as $refundOrder)
                            <form method="POST" action="{{ route('dashboard.accounts.festivals.ticket-orders.refund', [$account, $edition, $refundOrder]) }}" class="grid gap-3 rounded-xl bg-white p-4 md:grid-cols-[minmax(0,1fr)_minmax(16rem,1fr)_auto] md:items-end">
                                @csrf
                                <div>
                                    <strong class="font-mono text-sm">{{ $refundOrder->order_id }}</strong>
                                    <p class="mt-1 text-sm text-slate-600">{{ $refundOrder->buyer_name }} · {{ \App\Support\MoneyFormatter::format($refundOrder->amount_cents, $refundOrder->currency) }}</p>
                                    @if ($refundOrder->discount_cents > 0)
                                        <p class="mt-1 text-xs font-semibold text-emerald-700">{{ __('app.subtotal') }} {{ \App\Support\MoneyFormatter::format($refundOrder->subtotal_cents, $refundOrder->currency) }} · {{ $refundOrder->promo_code }} −{{ \App\Support\MoneyFormatter::format($refundOrder->discount_cents, $refundOrder->currency) }}</p>
                                    @endif
                                </div>
                                <input type="hidden" name="view" value="{{ $ticketView }}">
                <label><span class="crm-label">{{ __('app.refund_reason') }}</span><input name="reason" required maxlength="2000" class="crm-field"></label>
                                <x-ui.button type="submit" variant="danger">{{ __('app.festival_record_refund') }}</x-ui.button>
                            </form>
                        @endforeach
                    </div>
                </section>
            @endif
    @if ($hall['applicable'])
        <nav class="flex gap-2" aria-label="{{ __('app.festival_journey.map.view') }}">
            @foreach (['map', 'list'] as $view)
                <x-ui.button :href="route('dashboard.accounts.festivals.tickets.sold', array_merge([$account, $edition], request()->except(['page', 'view']), ['view' => $view]))" :variant="$ticketView === $view ? 'primary' : 'secondary'">{{ __('app.festival_journey.map.'.$view) }}</x-ui.button>
            @endforeach
        </nav>
    @endif
            <x-ui.filter-bar :action="route('dashboard.accounts.festivals.tickets.sold', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.tickets.sold', [$account, $edition])" class="lg:grid-cols-4">
                <input type="hidden" name="view" value="{{ $ticketView }}">
                <label><span class="crm-label">{{ __('app.search') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_ticket_search_placeholder') }}"></label>
                <label><span class="crm-label">{{ __('app.festival_ticket_type') }}</span><select name="type" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach($ticketTypeOptions as $type)<option value="{{ $type->id }}" @selected($filters['type'] === (string) $type->id)>{{ $type->name }}</option>@endforeach</select></label>
                <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach(\App\Enums\FestivalTicketStatus::cases() as $status)<option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ __('app.festival_ticket_status_'.$status->value) }}</option>@endforeach</select></label>
                <label><span class="crm-label">{{ __('app.festival_ticket_source') }}</span><select name="source" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach(\App\Enums\FestivalTicketOrderSource::cases() as $source)<option value="{{ $source->value }}" @selected($filters['source'] === $source->value)>{{ __('app.festival_ticket_source_'.$source->value) }}</option>@endforeach</select></label>
            </x-ui.filter-bar>

    @if ($ticketView === 'map')
        <x-festivals.seat-map :projection="$hall" :refresh-url="route('dashboard.accounts.festivals.tickets.seat-map', array_merge([$account, $edition], request()->only(['q', 'status', 'type', 'source', 'ticket']), ['state_only' => 1]))" />
    @else
            <div class="space-y-4">
                @php
                    $shownRefundOrderIds = [];
                @endphp
                @forelse ($tickets as $ticket)
                    @php
                        $order = $ticket->order;
                        $receipt = $order->fiscalReceipt;
                        $canRefund = $order->source === \App\Enums\FestivalTicketOrderSource::Checkout
                            && $order->status === \App\Enums\FestivalTicketOrderStatus::Paid
                            && ! isset($shownRefundOrderIds[$order->id]);
                    @endphp
                    <article data-festival-ticket="{{ $ticket->id }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><strong class="font-mono text-base text-slate-950">{{ $ticket->code }}</strong><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_ticket_status_'.$ticket->status->value) }}</span><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $order->source === \App\Enums\FestivalTicketOrderSource::Manual ? 'bg-violet-100 text-violet-800' : 'bg-sky-100 text-sky-800' }}">{{ __('app.festival_ticket_source_'.$order->source->value) }}</span><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $ticket->is_checked_in ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-700' }}">{{ $ticket->is_checked_in ? __('app.festival_checked_in') : __('app.festival_not_checked_in') }}</span></div><h3 class="mt-3 text-lg font-semibold text-slate-950">{{ $ticket->holder_name ?: $order->buyer_name }}</h3><p class="mt-1 break-words text-sm text-slate-600">{{ $order->buyer_name }} · {{ $order->buyer_email }}@if($order->buyer_phone) · {{ $order->buyer_phone }}@endif</p></div>
                            <div class="text-left xl:text-right"><strong class="text-lg text-slate-950">{{ $order->source === \App\Enums\FestivalTicketOrderSource::Manual ? __('app.festival_complimentary') : \App\Support\MoneyFormatter::format($order->amount_cents, $order->currency) }}</strong>@if($order->discount_cents > 0)<p class="mt-1 text-xs font-semibold text-emerald-700">{{ $order->promo_code }} · −{{ \App\Support\MoneyFormatter::format($order->discount_cents, $order->currency) }}</p>@endif<p class="mt-1 text-xs text-slate-500">{{ ($order->issued_at ?: $order->paid_at)?->timezone($edition->timezone)->format('d.m.Y H:i') ?? '—' }}</p></div>
                        </div>
                        <p class="mt-3 text-sm font-semibold" data-festival-ticket-seat>{{ $ticket->seat?->label() ?? ($ticket->admissionType?->delivery_mode === \App\Enums\FestivalAdmissionDeliveryMode::OnlineStream ? __('app.festival_admission_delivery_online_stream') : __('app.festival_journey.general_admission')) }}</p>
                        <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('app.festival_ticket_type') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $ticket->orderItem->admission_name }}</dd></div>
                            <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('app.order') }}</dt><dd class="mt-1 break-all font-mono text-xs text-slate-900">{{ $order->order_id }}</dd></div>
                            @if ($order->source === \App\Enums\FestivalTicketOrderSource::Manual)
                                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('app.festival_issued_by') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $order->issuer?->name ?: '—' }}</dd></div>
                                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('app.festival_issued_at') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $order->issued_at?->timezone($edition->timezone)->format('d.m.Y H:i') ?? '—' }}</dd></div>
                            @else
                                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('app.festival_payment') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $order->provider ?: '—' }} · {{ __('app.festival_order_'.$order->status->value) }}</dd><span class="mt-1 block break-all text-xs text-slate-500">{{ $order->gateway_payment_id ?: $order->gateway_invoice_id ?: '—' }}</span></div>
                                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('app.festival_fiscal_receipt') }}</dt><dd class="mt-1 font-semibold text-slate-900">@if($order->amount_cents === 0){{ __('app.festival_fiscal_not_required') }}@elseif($receipt){{ __('app.fiscal_status_'.$receipt->status->value) }}@else{{ __('app.fiscal_status_pending') }}@endif</dd>@if($receipt?->fiscal_number)<span class="mt-1 block break-all text-xs text-slate-500">{{ $receipt->fiscal_number }}</span>@endif @if($receipt?->last_error)<span class="mt-1 block text-xs text-rose-700">{{ $receipt->last_error }}</span>@endif</div>
                            @endif
                        </dl>
                        @if ($ticket->status === \App\Enums\FestivalTicketStatus::Valid || $canRefund)
                            <div class="mt-4 grid gap-3 border-t border-stone-100 pt-4 md:grid-cols-2">
                                @if ($ticket->status === \App\Enums\FestivalTicketStatus::Valid)
                                    <form method="POST" action="{{ route('dashboard.accounts.festivals.tickets.void', [$account, $edition, $ticket]) }}" class="flex gap-2">
                                        @csrf
                                        <input name="reason" required maxlength="2000" class="crm-field" placeholder="{{ __('app.festival_ticket_void_reason') }}">
                                        <x-ui.button type="submit" variant="danger">{{ __('app.festival_void_ticket') }}</x-ui.button>
                                    </form>
                                @endif
                                @if ($canRefund)
                                    @php
                                        $shownRefundOrderIds[$order->id] = true;
                                    @endphp
                                    <form method="POST" action="{{ route('dashboard.accounts.festivals.ticket-orders.refund', [$account, $edition, $order]) }}" class="flex gap-2">
                                        @csrf
                                        <input name="reason" required maxlength="2000" class="crm-field" placeholder="{{ __('app.refund_reason') }}">
                                        <x-ui.button type="submit" variant="danger">{{ __('app.festival_record_refund') }}</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </article>
                @empty
                    <x-ui.empty-state icon="ticket">{{ __('app.festival_bought_tickets_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
            <div>{{ $tickets->links() }}</div>
    @endif
</x-festivals.staff.workspace>
@endsection
