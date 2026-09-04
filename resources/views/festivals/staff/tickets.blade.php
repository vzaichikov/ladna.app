@extends('layouts.app')

@section('title', __('app.festival_tab_tickets_entrance').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_tickets_title')" :copy="__('app.festival_tickets_copy')">
        <x-slot:actions>
            @if ($workspacePermissions['finance'])
                <x-ui.button :href="route('dashboard.accounts.festivals.promo-codes.index', [$account, $edition])" variant="secondary"><x-ui.icon name="ticket" class="h-4 w-4" /> {{ __('app.promo_codes') }}</x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.festivals.tickets.issue', [$account, $edition])"><x-ui.icon name="ticket" class="h-4 w-4" /> {{ __('app.festival_issue_tickets') }}</x-ui.button>
            @endif
            @if ($workspacePermissions['ticket_check_in'] || (auth()->user()?->can('doorStaff', $account) ?? false))
                <x-ui.button :href="route('dashboard.accounts.festivals.scanner', [$account, $edition])"><x-ui.icon name="qr-code" class="h-4 w-4" /> {{ __('app.festival_open_scanner') }}</x-ui.button>
            @endif
            @can('doorStaff', $account)
                <x-ui.button :href="route('dashboard.accounts.festivals.attendance', [$account, $edition])" variant="secondary"><x-ui.icon name="monitor" class="h-4 w-4" /> {{ __('app.festival_entrance_monitor') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @if ($workspacePermissions['finance'])
            <div class="rounded-2xl bg-white p-5 shadow-crm"><span class="text-sm text-slate-500">{{ __('app.festival_paid_orders') }}</span><strong class="mt-1 block text-2xl">{{ $admissionReport['paid_orders'] }}</strong></div>
            <div class="rounded-2xl bg-white p-5 shadow-crm"><span class="text-sm text-slate-500">{{ __('app.festival_admission_revenue') }}</span><strong class="mt-1 block text-2xl">@forelse($admissionReport['revenue_by_currency'] as $currency => $amountCents)@if(! $loop->first) · @endif{{ \App\Support\MoneyFormatter::format($amountCents, $currency) }}@empty{{ \App\Support\MoneyFormatter::format(0, $account->default_currency) }}@endforelse</strong></div>
        @endif
        <div class="rounded-2xl bg-white p-5 shadow-crm"><span class="text-sm text-slate-500">{{ __('app.festival_tickets_issued') }}</span><strong class="mt-1 block text-2xl">{{ $admissionReport['tickets'] }}</strong></div>
        <div class="rounded-2xl bg-white p-5 shadow-crm"><span class="text-sm text-slate-500">{{ __('app.festival_tickets_checked_in') }}</span><strong class="mt-1 block text-2xl">{{ $admissionReport['checked_in'] }}</strong></div>
    </div>

    @if ($workspacePermissions['finance'])
        <nav class="flex gap-1 overflow-x-auto rounded-2xl bg-slate-100 p-1" aria-label="{{ __('app.festival_ticket_tabs') }}">
            <a href="{{ route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'types']) }}" class="whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-semibold {{ $tab === 'types' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-950' }}" @if($tab === 'types') aria-current="page" @endif>{{ __('app.festival_ticket_types_tab') }}</a>
            <a href="{{ route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'sold']) }}" class="whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-semibold {{ $tab === 'sold' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-950' }}" @if($tab === 'sold') aria-current="page" @endif>{{ __('app.festival_bought_tickets_tab') }}</a>
            <a href="{{ route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'orders']) }}" class="whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-semibold {{ $tab === 'orders' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-950' }}" @if($tab === 'orders') aria-current="page" @endif>{{ __('app.festival_ticket_orders_tab') }}</a>
        </nav>

        @if ($tab === 'types')
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_admission_types') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_admission_types_table_copy') }}</p></div>
                <x-ui.button :href="route('dashboard.accounts.festivals.admission-types.create', [$account, $edition])"><x-ui.icon name="plus" class="h-4 w-4" /> {{ __('app.festival_add_admission_type') }}</x-ui.button>
            </div>

            <x-ui.filter-bar :action="route('dashboard.accounts.festivals.tickets', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'types'])" class="sm:grid-cols-2">
                <input type="hidden" name="tab" value="types">
                <label><span class="crm-label">{{ __('app.search') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_admission_search_placeholder') }}"></label>
                <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
            </x-ui.filter-bar>

            @error('admission_type') <div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ $message }}</div> @enderror

            <x-ui.panel padding="none" class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-[980px] w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">{{ __('app.festival_ticket_type') }}</th><th class="px-4 py-3">{{ __('app.status') }}</th><th class="px-4 py-3">{{ __('app.festival_inventory') }}</th><th class="px-4 py-3">{{ __('app.festival_sold_held') }}</th><th class="px-4 py-3">{{ __('app.festival_remaining') }}</th><th class="px-4 py-3">{{ __('app.price') }}</th><th class="px-4 py-3">{{ __('app.festival_sales_window') }}</th><th class="px-4 py-3">{{ __('app.festival_lock_state') }}</th><th class="px-4 py-3 text-right">{{ __('app.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($admissionTypes as $admissionType)
                                @php
                                    $availability = $admissionAvailability[$admissionType->id];
                                @endphp
                                <tr class="align-top">
                                    <td class="px-4 py-4"><strong class="text-slate-950">{{ $admissionType->name }}</strong>@if($admissionType->description)<p class="mt-1 max-w-xs text-xs text-slate-500">{{ $admissionType->description }}</p>@endif</td>
                                    <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $admissionType->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-700' }}">{{ $admissionType->is_active ? __('app.active') : __('app.inactive') }}</span></td>
                                    <td class="px-4 py-4 font-semibold">{{ $admissionType->inventory }}</td>
                                    <td class="px-4 py-4"><span class="font-semibold">{{ $availability['sold'] }}</span><span class="text-slate-400"> / {{ $availability['held'] }}</span></td>
                                    <td class="px-4 py-4 font-semibold">{{ $availability['remaining'] }}</td>
                                    <td class="px-4 py-4"><strong>{{ \App\Support\MoneyFormatter::format($availability['current_price_cents'], $account->default_currency) }}</strong><span class="mt-1 block text-xs text-slate-500">{{ __('app.festival_price_tier_'.$availability['price_tier']) }}</span></td>
                                    <td class="px-4 py-4 text-xs text-slate-600"><span class="block">{{ $admissionType->sales_starts_at?->timezone($edition->timezone)->format('d.m.Y H:i') ?? '—' }}</span><span class="block">{{ $admissionType->sales_ends_at?->timezone($edition->timezone)->format('d.m.Y H:i') ?? '—' }}</span></td>
                                    <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $availability['locked'] ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800' }}">{{ $availability['locked'] ? __('app.festival_locked_after_purchase') : __('app.festival_editable') }}</span></td>
                                    <td class="px-4 py-4"><div class="flex justify-end gap-2">
                                        @unless ($availability['locked'])
                                            <x-ui.action-button :href="route('dashboard.accounts.festivals.admission-types.edit', [$account, $edition, $admissionType])" icon="edit" :label="__('app.edit')" />
                                        @endunless
                                        @unless ($availability['has_history'])
                                            <form method="POST" action="{{ route('dashboard.accounts.festivals.admission-types.destroy', [$account, $edition, $admissionType]) }}" data-confirm-delete data-confirm-title="{{ __('app.festival_delete_admission_type_title') }}" data-confirm-body="{{ __('app.festival_delete_admission_type_copy') }}">
                                                @csrf @method('DELETE')
                                                <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                                            </form>
                                        @endunless
                                    </div></td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500">{{ $filters['q'] || $filters['status'] ? __('app.no_data') : __('app.festival_admission_types_empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.panel>
            <div>{{ $admissionTypes->links() }}</div>
        @elseif ($tab === 'sold')
            <div><h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_bought_tickets_tab') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_bought_tickets_copy') }}</p></div>
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
                                <label><span class="crm-label">{{ __('app.refund_reason') }}</span><input name="reason" required maxlength="2000" class="crm-field"></label>
                                <x-ui.button type="submit" variant="danger">{{ __('app.festival_record_refund') }}</x-ui.button>
                            </form>
                        @endforeach
                    </div>
                </section>
            @endif
            <x-ui.filter-bar :action="route('dashboard.accounts.festivals.tickets', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'sold'])" class="lg:grid-cols-4">
                <input type="hidden" name="tab" value="sold">
                <label><span class="crm-label">{{ __('app.search') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_ticket_search_placeholder') }}"></label>
                <label><span class="crm-label">{{ __('app.festival_ticket_type') }}</span><select name="type" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach($ticketTypeOptions as $type)<option value="{{ $type->id }}" @selected($filters['type'] === (string) $type->id)>{{ $type->name }}</option>@endforeach</select></label>
                <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach(\App\Enums\FestivalTicketStatus::cases() as $status)<option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ __('app.festival_ticket_status_'.$status->value) }}</option>@endforeach</select></label>
                <label><span class="crm-label">{{ __('app.festival_ticket_source') }}</span><select name="source" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach(\App\Enums\FestivalTicketOrderSource::cases() as $source)<option value="{{ $source->value }}" @selected($filters['source'] === $source->value)>{{ __('app.festival_ticket_source_'.$source->value) }}</option>@endforeach</select></label>
            </x-ui.filter-bar>

            <div class="space-y-4">
                @forelse ($tickets as $ticket)
                    @php
                        $order = $ticket->order;
                        $receipt = $order->fiscalReceipt;
                    @endphp
                    <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><strong class="font-mono text-base text-slate-950">{{ $ticket->code }}</strong><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_ticket_status_'.$ticket->status->value) }}</span><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $order->source === \App\Enums\FestivalTicketOrderSource::Manual ? 'bg-violet-100 text-violet-800' : 'bg-sky-100 text-sky-800' }}">{{ __('app.festival_ticket_source_'.$order->source->value) }}</span><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $ticket->is_checked_in ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-700' }}">{{ $ticket->is_checked_in ? __('app.festival_checked_in') : __('app.festival_not_checked_in') }}</span></div><h3 class="mt-3 text-lg font-semibold text-slate-950">{{ $ticket->holder_name ?: $order->buyer_name }}</h3><p class="mt-1 break-words text-sm text-slate-600">{{ $order->buyer_name }} · {{ $order->buyer_email }}@if($order->buyer_phone) · {{ $order->buyer_phone }}@endif</p></div>
                            <div class="text-left xl:text-right"><strong class="text-lg text-slate-950">{{ $order->source === \App\Enums\FestivalTicketOrderSource::Manual ? __('app.festival_complimentary') : \App\Support\MoneyFormatter::format($order->amount_cents, $order->currency) }}</strong>@if($order->discount_cents > 0)<p class="mt-1 text-xs font-semibold text-emerald-700">{{ $order->promo_code }} · −{{ \App\Support\MoneyFormatter::format($order->discount_cents, $order->currency) }}</p>@endif<p class="mt-1 text-xs text-slate-500">{{ ($order->issued_at ?: $order->paid_at)?->timezone($edition->timezone)->format('d.m.Y H:i') ?? '—' }}</p></div>
                        </div>
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
                        @if ($ticket->status === \App\Enums\FestivalTicketStatus::Valid)
                            <div class="mt-4 grid gap-3 border-t border-stone-100 pt-4 md:grid-cols-2">
                                <form method="POST" action="{{ route('dashboard.accounts.festivals.tickets.void', [$account, $edition, $ticket]) }}" class="flex gap-2">
                                    @csrf
                                    <input name="reason" required maxlength="2000" class="crm-field" placeholder="{{ __('app.festival_ticket_void_reason') }}">
                                    <x-ui.button type="submit" variant="danger">{{ __('app.festival_void_ticket') }}</x-ui.button>
                                </form>
                                @if ($order->source === \App\Enums\FestivalTicketOrderSource::Checkout && $order->status === \App\Enums\FestivalTicketOrderStatus::Paid && $order->tickets->first()?->is($ticket))
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
        @else
            <div>
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_ticket_orders_tab') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_ticket_orders_copy') }}</p>
            </div>

            <x-ui.filter-bar :action="route('dashboard.accounts.festivals.tickets', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'orders'])" class="xl:grid-cols-4">
                <input type="hidden" name="tab" value="orders">
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
        @endif
    @else
        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sm text-sky-900"><div class="flex items-start gap-3"><x-ui.icon name="qr-code" class="mt-0.5 h-5 w-5 shrink-0" /><div><strong>{{ __('app.festival_scanner_access_title') }}</strong><p class="mt-1">{{ __('app.festival_scanner_access_copy') }}</p></div></div></div>
    @endif
</x-festivals.staff.workspace>
@endsection
