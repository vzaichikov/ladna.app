<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FiscalReceiptStatus;
use App\Models\FestivalEditionPurchase;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CompleteFestivalEditionPurchase
{
    public function __construct(private readonly FiscalReceiptService $fiscalReceipts) {}

    public function execute(FestivalEditionPurchase $purchase, PaymentCallbackResult $callback): FestivalEditionPurchase
    {
        $previousStatus = $purchase->status;

        $purchase = DB::transaction(function () use ($purchase, $callback, &$previousStatus): FestivalEditionPurchase {
            $purchase = FestivalEditionPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $previousStatus = $purchase->status;
            $this->assertMatches($purchase, $callback);

            if ($purchase->status === FestivalEditionPurchaseStatus::PaymentReversed) {
                return $purchase;
            }

            if (in_array($purchase->status, [FestivalEditionPurchaseStatus::Available, FestivalEditionPurchaseStatus::Redeemed], true) && $callback->status === PaymentCallbackStatus::Paid) {
                return $purchase;
            }

            $purchase->forceFill([
                'gateway_invoice_id' => $callback->gatewayInvoiceId ?? $purchase->gateway_invoice_id,
                'gateway_payment_id' => $callback->gatewayPaymentId ?? $purchase->gateway_payment_id,
                'gateway_status' => $callback->gatewayStatus,
                'last_callback_payload' => $callback->payload,
                'failure_reason' => $callback->failureReason,
            ]);

            $wasGranted = in_array($purchase->status, [FestivalEditionPurchaseStatus::Available, FestivalEditionPurchaseStatus::Redeemed], true);
            if ($callback->status === PaymentCallbackStatus::Paid) {
                $purchase->forceFill([
                    'status' => $purchase->festival_edition_id ? FestivalEditionPurchaseStatus::Redeemed : FestivalEditionPurchaseStatus::Available,
                    'paid_at' => $callback->paidAt ?? now(),
                    'failed_at' => null,
                    'cancelled_at' => null,
                    'expired_at' => null,
                    'reversed_at' => null,
                ]);
            } elseif ($wasGranted && $callback->status === PaymentCallbackStatus::Cancelled && in_array($callback->gatewayStatus, ['reversed', 'refunded'], true)) {
                $purchase->forceFill(['status' => FestivalEditionPurchaseStatus::PaymentReversed, 'reversed_at' => now()]);
            } elseif (! $wasGranted) {
                $purchase->forceFill(match ($callback->status) {
                    PaymentCallbackStatus::Failed => ['status' => FestivalEditionPurchaseStatus::PaymentFailed, 'failed_at' => now()],
                    PaymentCallbackStatus::Cancelled => ['status' => FestivalEditionPurchaseStatus::PaymentCancelled, 'cancelled_at' => now()],
                    PaymentCallbackStatus::Expired => ['status' => FestivalEditionPurchaseStatus::PaymentExpired, 'expired_at' => now()],
                    default => ['status' => FestivalEditionPurchaseStatus::PaymentPending],
                });
            }

            $purchase->save();

            return $purchase->refresh();
        }, attempts: 3);

        if ($purchase->status === FestivalEditionPurchaseStatus::Available && ! in_array($previousStatus, [FestivalEditionPurchaseStatus::Available, FestivalEditionPurchaseStatus::Redeemed], true)) {
            $receipt = $this->fiscalReceipts->fiscalizeFestivalEditionPurchase($purchase);
            if ($receipt === null || $receipt->status === FiscalReceiptStatus::Failed) {
                report(new RuntimeException("Fiscalization requires attention for Festival purchase {$purchase->id}."));
            }
        }

        return $purchase;
    }

    private function assertMatches(FestivalEditionPurchase $purchase, PaymentCallbackResult $callback): void
    {
        $references = array_filter([$purchase->order_id, $purchase->gateway_invoice_id, $purchase->gateway_payment_id]);
        if ($callback->orderId !== '' && ! in_array($callback->orderId, $references, true) && ! in_array($callback->gatewayInvoiceId, $references, true)) {
            throw new InvalidPaymentCallbackException('Callback order does not match Festival purchase.');
        }
        if ($callback->amountCents !== null && $callback->amountCents !== $purchase->amount_cents) {
            throw new InvalidPaymentCallbackException('Callback amount does not match Festival purchase.');
        }
        if ($callback->currency !== null && strtoupper($callback->currency) !== $purchase->currency) {
            throw new InvalidPaymentCallbackException('Callback currency does not match Festival purchase.');
        }
    }
}
