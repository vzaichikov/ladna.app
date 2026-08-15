@extends('layouts.app')

@section('title', __('app.event_orders').' - '.$event->title)

@section('content')
    @php
        $formatDateTime = fn ($date): string => \App\Support\DateTimePresenter::format($date, $account, 'd.m.Y H:i') ?? __('app.not_set');
        $providerLabelResolver = static function (?string $provider): string {
            if (blank($provider)) {
                return __('app.not_set');
            }

            $translationKey = 'app.provider_'.$provider;
            $label = __($translationKey);

            return $label === $translationKey ? config('integrations.providers.'.$provider.'.label', $provider) : $label;
        };
    @endphp

    <div class="w-full min-w-0 space-y-6" data-event-admin-page>
        <x-ui.page-header :title="__('app.event_orders')" :copy="__('app.event_orders_help')" />
        <x-ui.event-navigation :account="$account" :event="$event" active="orders" />

        @if ($urgentRefundsCount > 0)
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 font-semibold text-rose-900" role="alert">
                {{ trans_choice('app.event_urgent_refunds', $urgentRefundsCount, ['count' => $urgentRefundsCount]) }}
            </div>
        @endif

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
            <div class="overflow-x-auto [contain:paint]">
                <table class="min-w-[67rem] divide-y divide-stone-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="w-40 px-5 py-3">{{ __('app.event_order') }}</th>
                            <th class="min-w-56 px-5 py-3">{{ __('app.event_buyer') }} / {{ __('app.event_ticket') }}</th>
                            <th class="min-w-56 px-5 py-3">{{ __('app.event_payment_details') }}</th>
                            <th class="min-w-52 px-5 py-3">{{ __('app.event_fiscalization') }}</th>
                            <th class="min-w-40 px-5 py-3">{{ __('app.status') }}</th>
                            <th class="w-24 px-5 py-3 text-right"><span class="sr-only">{{ __('app.actions') }}</span></th>
                        </tr>
                    </thead>

                    @forelse ($orders as $order)
                        @php
                            $latestEmailDelivery = $order->emailDeliveries->first();
                            $receipt = $order->fiscalReceipt;
                            $manualPaymentMethod = $order->manualPaymentMethod();
                            $orderStatusClass = match ($order->status) {
                                \App\Enums\EventOrderStatus::Paid, \App\Enums\EventOrderStatus::Refunded => 'crm-status-active',
                                \App\Enums\EventOrderStatus::RefundRequired, \App\Enums\EventOrderStatus::PaidRequiresRefund, \App\Enums\EventOrderStatus::Failed => 'crm-status-danger',
                                \App\Enums\EventOrderStatus::Pending => 'crm-status-scheduled',
                                default => 'crm-status-muted',
                            };
                            $fiscalStatusClass = match ($receipt?->status) {
                                \App\Enums\FiscalReceiptStatus::Fiscalized => 'crm-status-active',
                                \App\Enums\FiscalReceiptStatus::Processing, \App\Enums\FiscalReceiptStatus::Pending => 'crm-status-scheduled',
                                \App\Enums\FiscalReceiptStatus::Failed => 'crm-status-danger',
                                default => 'crm-status-muted',
                            };
                            $canResend = $event->status !== \App\Enums\EventStatus::Cancelled
                                && filled($order->buyer_email)
                                && $order->tickets->contains('status', \App\Enums\EventTicketStatus::Valid);
                            $canRefund = $order->amount_cents > 0 && in_array($order->status, [
                                \App\Enums\EventOrderStatus::Paid,
                                \App\Enums\EventOrderStatus::RefundRequired,
                                \App\Enums\EventOrderStatus::PaidRequiresRefund,
                            ], true);
                        @endphp

                        <tbody class="divide-y divide-stone-100 border-b border-stone-200 last:border-b-0" data-event-order-group="{{ $order->id }}">
                            <tr class="align-top" data-event-order-row="{{ $order->id }}">
                                <td class="px-5 py-4">
                                    <strong class="block break-all font-mono text-xs text-slate-950">{{ $order->order_id }}</strong>
                                    <span class="mt-2 block whitespace-nowrap text-xs text-slate-500">{{ $formatDateTime($order->created_at) }}</span>
                                    <span class="mt-2 inline-flex {{ $order->isManuallyIssued() ? 'crm-status-muted' : 'crm-status-active' }}">
                                        {{ $order->isManuallyIssued() ? __('app.event_ticket_source_manual') : __('app.event_ticket_source_online') }}
                                    </span>
                                    @if ($order->issuedBy)
                                        <span class="mt-2 block text-xs text-slate-500">{{ __('app.event_issued_by', ['name' => $order->issuedBy->name]) }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <strong class="block text-slate-950">{{ $order->buyer_name }}</strong>
                                    @if (filled($order->buyer_email))
                                        <span class="mt-1 block break-all text-xs text-slate-500">{{ $order->buyer_email }}</span>
                                    @endif
                                    @if (filled($order->buyer_phone))
                                        <span class="mt-1 block text-xs text-slate-500">{{ $order->buyer_phone }}</span>
                                    @endif
                                    <span class="mt-3 block text-xs font-semibold text-slate-700">
                                        {{ trans_choice('app.event_order_ticket_count', $order->items->sum('quantity'), ['count' => $order->items->sum('quantity')]) }}
                                    </span>
                                    <span class="mt-1 block text-xs text-slate-500">
                                        {{ __('app.event_delivery_audit') }}: {{ $order->emailDeliveries->count() }}
                                        @if ($latestEmailDelivery)
                                            · {{ __($latestEmailDelivery->status->labelKey()) }} · {{ $formatDateTime($latestEmailDelivery->created_at) }}
                                        @endif
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <strong class="block text-base text-slate-950">{{ \App\Support\MoneyFormatter::format($order->amount_cents, $order->currency) }}</strong>
                                    <dl class="mt-2 space-y-1.5 break-words text-xs text-slate-500">
                                        <div>
                                            <dt class="inline font-semibold text-slate-700">{{ __('app.payment_provider') }}:</dt>
                                            <dd class="inline">
                                                @if ($manualPaymentMethod)
                                                    {{ __('app.event_manual_method_'.$manualPaymentMethod) }}
                                                @elseif ($order->isManuallyIssued() && $order->amount_cents === 0)
                                                    {{ __('app.event_manual_payment_complimentary') }}
                                                @else
                                                    {{ $providerLabelResolver($order->provider) }}
                                                @endif
                                            </dd>
                                        </div>
                                        @if ($order->paid_at)
                                            <div><dt class="inline font-semibold text-slate-700">{{ __('app.event_paid_at') }}:</dt> <dd class="inline">{{ $formatDateTime($order->paid_at) }}</dd></div>
                                        @endif
                                        @if ($order->gateway_status)
                                            <div><dt class="inline font-semibold text-slate-700">{{ __('app.event_payment_gateway_status') }}:</dt> <dd class="inline">{{ $order->gateway_status }}</dd></div>
                                        @endif
                                        @if ($order->gateway_invoice_id)
                                            <div><dt class="inline font-semibold text-slate-700">{{ __('app.event_payment_gateway_invoice') }}:</dt> <dd class="inline break-all font-mono">{{ $order->gateway_invoice_id }}</dd></div>
                                        @endif
                                        @if ($order->gateway_payment_id)
                                            <div><dt class="inline font-semibold text-slate-700">{{ __('app.event_payment_gateway_payment') }}:</dt> <dd class="inline break-all font-mono">{{ $order->gateway_payment_id }}</dd></div>
                                        @endif
                                    </dl>
                                    @if ($order->failure_reason)
                                        <p class="mt-2 text-xs leading-5 text-rose-700">{{ $order->failure_reason }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    @if ($order->amount_cents === 0)
                                        <span class="crm-status-muted">{{ __('app.event_fiscalization_not_required') }}</span>
                                    @elseif ($receipt)
                                        <span class="{{ $fiscalStatusClass }}">{{ __('app.fiscal_status_'.$receipt->status->value) }}</span>
                                        <dl class="mt-2 space-y-1.5 break-words text-xs text-slate-500">
                                            <div><dt class="inline font-semibold text-slate-700">{{ __('app.event_fiscal_provider') }}:</dt> <dd class="inline">{{ $providerLabelResolver($receipt->provider->value) }}</dd></div>
                                            @if ($receipt->fiscal_number)
                                                <div><dt class="inline font-semibold text-slate-700">{{ __('app.fiscal_receipt_number') }}:</dt> <dd class="inline break-all font-mono">{{ $receipt->fiscal_number }}</dd></div>
                                            @endif
                                            @if ($receipt->provider_status)
                                                <div><dt class="inline font-semibold text-slate-700">{{ __('app.event_fiscal_provider_status') }}:</dt> <dd class="inline">{{ $receipt->provider_status }}</dd></div>
                                            @endif
                                            <div><dt class="inline font-semibold text-slate-700">{{ __('app.event_fiscal_attempts') }}:</dt> <dd class="inline">{{ $receipt->attempts }}</dd></div>
                                            @if ($receipt->fiscalized_at || $receipt->failed_at || $receipt->sent_at)
                                                <div><dt class="inline font-semibold text-slate-700">{{ __('app.event_fiscal_updated_at') }}:</dt> <dd class="inline">{{ $formatDateTime($receipt->fiscalized_at ?? $receipt->failed_at ?? $receipt->sent_at) }}</dd></div>
                                            @endif
                                        </dl>
                                        @if ($receipt->last_error)
                                            <p class="mt-2 text-xs leading-5 text-rose-700">{{ $receipt->last_error }}</p>
                                        @endif
                                    @elseif ($order->status === \App\Enums\EventOrderStatus::Pending)
                                        <span class="crm-status-muted">{{ __('app.event_fiscalization_after_payment') }}</span>
                                    @elseif (! $fiscalizationEnabled)
                                        <span class="crm-status-muted">{{ __('app.event_fiscalization_not_configured') }}</span>
                                    @elseif (! in_array($order->status, [\App\Enums\EventOrderStatus::Paid, \App\Enums\EventOrderStatus::RefundRequired], true))
                                        <span class="crm-status-muted">{{ __('app.event_fiscalization_not_created') }}</span>
                                    @else
                                        <span class="crm-status-scheduled">{{ __('app.fiscal_status_pending') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <span class="{{ $orderStatusClass }}">{{ __('app.event_order_status_'.$order->status->value) }}</span>
                                    @if ($order->refund_reason)
                                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ $order->refund_reason }}</p>
                                    @endif
                                    @if ($order->refunded_at)
                                        <p class="mt-1 text-xs text-slate-500">{{ $formatDateTime($order->refunded_at) }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-2">
                                        @if ($canResend)
                                            <form
                                                method="POST"
                                                action="{{ route('dashboard.accounts.events.orders.resend', [$account, $event, $order]) }}"
                                                data-confirm-action
                                                data-confirm-title="{{ __('app.event_confirm_resend_title') }}"
                                                data-confirm-body="{{ __('app.event_confirm_resend_body', ['order' => $order->order_id]) }}"
                                                data-confirm-accept="{{ __('app.event_resend_tickets') }}"
                                                data-confirm-variant="primary"
                                                data-confirm-icon="send"
                                            >
                                                @csrf
                                                <x-ui.action-button type="submit" icon="send" :label="__('app.event_resend_tickets')" />
                                            </form>
                                        @endif
                                        @if ($canRefund)
                                            <form
                                                method="POST"
                                                action="{{ route('dashboard.accounts.events.orders.refund', [$account, $event, $order]) }}"
                                                data-confirm-action
                                                data-confirm-title="{{ __('app.event_confirm_refund_title') }}"
                                                data-confirm-body="{{ __('app.event_confirm_refund_body', ['order' => $order->order_id]) }}"
                                                data-confirm-accept="{{ __('app.event_mark_refunded') }}"
                                                data-confirm-variant="danger"
                                                data-confirm-icon="undo-2"
                                                data-confirm-reason-required="true"
                                                data-confirm-reason-maxlength="2000"
                                                data-confirm-reason-label="{{ __('app.event_external_refund_reason') }}"
                                                data-confirm-reason-placeholder="{{ __('app.event_external_refund_reason') }}"
                                            >
                                                @csrf
                                                <input type="hidden" name="reason" data-confirm-reason-output>
                                                <x-ui.action-button type="submit" variant="danger" icon="undo-2" :label="__('app.event_mark_refunded')" />
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>

                            @foreach ($order->tickets as $ticket)
                                <tr class="bg-slate-50/70 align-top" data-event-ticket-row="{{ $ticket->id }}">
                                    <td class="px-5 py-3" aria-hidden="true"></td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-start gap-3">
                                            <x-ui.icon name="corner-down-right" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                                            <div class="min-w-0">
                                                <strong class="block text-slate-900">{{ $ticket->orderItem?->ticket_type_name ?? __('app.event_ticket') }}</strong>
                                                <span class="mt-1 block break-all font-mono text-xs text-slate-500">{{ $ticket->code }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <strong class="text-slate-800">{{ \App\Support\MoneyFormatter::format($ticket->orderItem?->unit_price_cents ?? 0, $order->currency) }}</strong>
                                        @if ($ticket->orderItem?->price_tier)
                                            <span class="mt-1 block text-xs text-slate-500">{{ __('app.event_price_tier_'.$ticket->orderItem->price_tier) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-xs leading-5 text-slate-500">{{ __('app.event_ticket_covered_by_order_receipt') }}</td>
                                    <td class="px-5 py-3">
                                        <span class="{{ $ticket->status === \App\Enums\EventTicketStatus::Valid ? 'crm-status-active' : 'crm-status-danger' }}">
                                            {{ __('app.event_ticket_status_'.$ticket->status->value) }}
                                        </span>
                                        <span class="mt-2 block text-xs text-slate-500">
                                            {{ $ticket->is_checked_in ? __('app.event_checked_in') : __('app.event_not_checked_in') }}
                                            @if ($ticket->checked_in_at)
                                                · {{ $formatDateTime($ticket->checked_in_at) }}
                                            @endif
                                        </span>
                                        @if ($ticket->void_reason)
                                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ $ticket->void_reason }}</p>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($ticket->status === \App\Enums\EventTicketStatus::Valid)
                                            <div class="flex justify-end">
                                                <form
                                                    method="POST"
                                                    action="{{ route('dashboard.accounts.events.orders.tickets.void', [$account, $event, $order, $ticket]) }}"
                                                    data-confirm-action
                                                    data-confirm-title="{{ __('app.event_confirm_void_title') }}"
                                                    data-confirm-body="{{ __('app.event_confirm_void_body', ['ticket' => $ticket->code]) }}"
                                                    data-confirm-accept="{{ __('app.event_void_ticket') }}"
                                                    data-confirm-variant="danger"
                                                    data-confirm-icon="ticket-x"
                                                    data-confirm-reason-required="true"
                                                    data-confirm-reason-maxlength="2000"
                                                    data-confirm-reason-label="{{ __('app.reason') }}"
                                                    data-confirm-reason-placeholder="{{ __('app.reason') }}"
                                                >
                                                    @csrf
                                                    <input type="hidden" name="reason" data-confirm-reason-output>
                                                    <x-ui.action-button type="submit" variant="danger" icon="ticket-x" :label="__('app.event_void_ticket')" />
                                                </form>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @empty
                        <tbody>
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-sm text-slate-500">{{ __('app.event_orders_empty') }}</td>
                            </tr>
                        </tbody>
                    @endforelse
                </table>
            </div>
        </div>

        @if ($orders->hasPages())
            {{ $orders->links() }}
        @endif
    </div>
@endsection
