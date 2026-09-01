<?php

namespace App\Actions;

use App\Enums\EmailScenario;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\IntegrationProvider;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventOrderItem;
use App\Models\EventTicketType;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteEventOrder
{
    public function __construct(
        private readonly IssueEventTickets $issueTickets,
        private readonly TransactionalMailDispatcher $mailDispatcher,
        private readonly FiscalReceiptService $fiscalReceipts,
    ) {}

    public function execute(EventOrder $order, PaymentCallbackResult $callback): EventOrder
    {
        [$completed, $transition] = DB::transaction(function () use ($order, $callback): array {
            $order = EventOrder::query()->with(['event', 'items.ticketType'])->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($callback->orderId !== $order->order_id
                || ($callback->amountCents !== null && $callback->amountCents !== $order->amount_cents)
                || ($callback->currency !== null && strtoupper($callback->currency) !== strtoupper($order->currency))) {
                throw new InvalidPaymentCallbackException('Callback does not match event order.');
            }

            if ($callback->isOlderThan($order->last_callback_payload)) {
                return [$order, null];
            }

            $isTerminal = $order->refunded_at !== null || in_array($order->status, [
                EventOrderStatus::Paid,
                EventOrderStatus::RefundRequired,
                EventOrderStatus::Refunded,
            ], true) || ($order->status === EventOrderStatus::PaidRequiresRefund
                && $order->provider !== IntegrationProvider::Monopay->value);

            if ($isTerminal
                || ($order->status === EventOrderStatus::PaidRequiresRefund && $callback->status !== PaymentCallbackStatus::Paid)) {
                return [$order, null];
            }

            if ($callback->status === PaymentCallbackStatus::Paid) {
                $event = Event::query()->whereKey($order->event_id)->lockForUpdate()->firstOrFail();
                $types = EventTicketType::query()->whereKey($order->items->pluck('event_ticket_type_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $lateMonopayPayment = $order->provider === IntegrationProvider::Monopay->value
                    && ($order->status !== EventOrderStatus::Pending
                        || $order->payment_expires_at?->isPast()
                        || $order->expires_at?->isPast());
                $eventAcceptsPayment = $event->status === EventStatus::Published && $event->starts_at->isFuture();
                $capacityAvailable = true;
                $typeCapacityAvailable = true;

                if (! $lateMonopayPayment) {
                    $otherReserved = (int) EventOrderItem::query()
                        ->where('event_id', $event->id)
                        ->where('event_order_id', '!=', $order->id)
                        ->whereHas('order', fn ($query) => $query
                            ->whereIn('status', [EventOrderStatus::Pending->value, EventOrderStatus::Paid->value, EventOrderStatus::RefundRequired->value])
                            ->where(fn ($query) => $query->where('status', '!=', EventOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
                        ->sum('quantity');
                    $capacityAvailable = $event->capacity === null || $otherReserved + $order->items->sum('quantity') <= $event->capacity;
                    $typeCapacityAvailable = $order->items->every(function ($item) use ($types, $order): bool {
                        $otherQuantity = (int) EventOrderItem::query()
                            ->where('event_ticket_type_id', $item->event_ticket_type_id)
                            ->where('event_order_id', '!=', $order->id)
                            ->whereHas('order', fn ($query) => $query
                                ->whereIn('status', [EventOrderStatus::Pending->value, EventOrderStatus::Paid->value, EventOrderStatus::RefundRequired->value])
                                ->where(fn ($query) => $query->where('status', '!=', EventOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
                            ->sum('quantity');

                        return $otherQuantity + $item->quantity <= $types[$item->event_ticket_type_id]->inventory;
                    });
                }

                if (! $eventAcceptsPayment || ! $capacityAvailable || ! $typeCapacityAvailable) {
                    $order->forceFill([
                        'status' => EventOrderStatus::PaidRequiresRefund,
                        'paid_at' => $callback->paidAt ?? now(),
                        'expires_at' => null,
                        'gateway_invoice_id' => $callback->gatewayInvoiceId,
                        'gateway_payment_id' => $callback->gatewayPaymentId,
                        'gateway_status' => $callback->gatewayStatus,
                        'last_callback_payload' => $callback->payload,
                        'failure_reason' => $eventAcceptsPayment
                            ? __('app.event_late_payment_no_capacity')
                            : __('app.event_late_payment_event_unavailable'),
                        'failed_at' => null,
                    ])->save();

                    return [$order->refresh(), 'requires_refund'];
                }

                $order->forceFill([
                    'status' => EventOrderStatus::Paid,
                    'paid_at' => $callback->paidAt ?? now(),
                    'expires_at' => null,
                    'gateway_invoice_id' => $callback->gatewayInvoiceId,
                    'gateway_payment_id' => $callback->gatewayPaymentId,
                    'gateway_status' => $callback->gatewayStatus,
                    'last_callback_payload' => $callback->payload,
                    'failure_reason' => null,
                    'failed_at' => null,
                ])->save();
                $this->issueTickets->execute($order);

                return [$order->refresh()->load('tickets'), 'paid'];
            }

            $order->forceFill([
                'status' => match ($callback->status) {
                    PaymentCallbackStatus::Expired => EventOrderStatus::Expired,
                    PaymentCallbackStatus::Cancelled => EventOrderStatus::Cancelled,
                    PaymentCallbackStatus::Failed => EventOrderStatus::Failed,
                    default => EventOrderStatus::Pending,
                },
                'gateway_status' => $callback->gatewayStatus,
                'last_callback_payload' => $callback->payload,
                'failure_reason' => $callback->failureReason,
                'failed_at' => $callback->status === PaymentCallbackStatus::Failed ? now() : null,
            ])->save();

            return [$order->refresh(), null];
        }, 3);

        if ($transition === 'paid') {
            if (filled($completed->buyer_email)) {
                $this->mailDispatcher->eventTicketsIssued($completed);
            }

            try {
                $this->fiscalReceipts->fiscalizeEventOrder($completed);
            } catch (Throwable $exception) {
                report($exception);
            }
        } elseif ($transition === 'requires_refund') {
            $this->mailDispatcher->eventBuyerNotice($completed, EmailScenario::EventPaymentAttention);
        }

        return $completed;
    }
}
