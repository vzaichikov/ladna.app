<?php

namespace App\Support\Sms;

use App\Enums\FiscalReceiptStatus;
use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Models\SmsTopUpPayment;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CompleteSmsTopUpPayment
{
    public function __construct(
        private readonly SmsWalletService $wallets,
        private readonly ResumeSmsNotificationsAfterTopUp $resumeNotifications,
        private readonly FiscalReceiptService $fiscalReceipts,
        private readonly SmsAccountNotifier $notifier,
    ) {}

    public function execute(SmsTopUpPayment $payment, PaymentCallbackResult $callback): SmsTopUpPayment
    {
        $previousStatus = $payment->status;

        $payment = DB::transaction(function () use ($payment, $callback, &$previousStatus): SmsTopUpPayment {
            $payment = SmsTopUpPayment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $previousStatus = $payment->status;
            $this->assertCallbackMatchesPayment($payment, $callback);

            if ($callback->isOlderThan($payment->last_callback_payload)) {
                return $payment;
            }

            if ($payment->status === SmsTopUpPaymentStatus::PaymentPaid && $callback->status === PaymentCallbackStatus::Paid) {
                return $payment;
            }

            $payment->forceFill([
                'gateway_invoice_id' => $callback->gatewayInvoiceId ?? $payment->gateway_invoice_id,
                'gateway_payment_id' => $callback->gatewayPaymentId ?? $payment->gateway_payment_id,
                'gateway_status' => $callback->gatewayStatus,
                'last_callback_payload' => $callback->payload,
                'failure_reason' => $callback->failureReason,
            ]);

            if ($callback->status === PaymentCallbackStatus::Paid) {
                $payment->forceFill([
                    'status' => SmsTopUpPaymentStatus::PaymentPaid,
                    'paid_at' => $callback->paidAt ?? now(),
                    'failed_at' => null,
                    'cancelled_at' => null,
                    'expired_at' => null,
                    'reversed_at' => null,
                ]);
            } elseif (
                $payment->status === SmsTopUpPaymentStatus::PaymentPaid
                && $callback->status === PaymentCallbackStatus::Cancelled
                && in_array($callback->gatewayStatus, ['reversed', 'refunded'], true)
            ) {
                $payment->forceFill([
                    'status' => SmsTopUpPaymentStatus::PaymentReversed,
                    'reversed_at' => now(),
                ]);
            } else {
                $payment->forceFill(match ($callback->status) {
                    PaymentCallbackStatus::Failed => [
                        'status' => SmsTopUpPaymentStatus::PaymentFailed,
                        'failed_at' => now(),
                    ],
                    PaymentCallbackStatus::Cancelled => [
                        'status' => SmsTopUpPaymentStatus::PaymentCancelled,
                        'cancelled_at' => now(),
                    ],
                    PaymentCallbackStatus::Expired => [
                        'status' => SmsTopUpPaymentStatus::PaymentExpired,
                        'expired_at' => now(),
                    ],
                    default => [
                        'status' => SmsTopUpPaymentStatus::PaymentPending,
                    ],
                });
            }

            $payment->save();

            return $payment->refresh();
        }, attempts: 3);

        if (
            $payment->status === SmsTopUpPaymentStatus::PaymentPaid
            && $previousStatus !== SmsTopUpPaymentStatus::PaymentPaid
        ) {
            $wallet = $this->wallets->creditTopUp($payment);

            if ($payment->kind === SmsTopUpKind::Automatic) {
                $this->wallets->markAutomaticTopUpSpent($wallet, $payment->amount_cents);
            }

            $this->resumeNotifications->execute($payment->account);
            $receipt = $this->fiscalReceipts->fiscalizeSmsTopUpPayment($payment);

            if ($receipt === null || $receipt->status === FiscalReceiptStatus::Failed) {
                report(new RuntimeException(
                    "Fiscalization requires attention for paid SMS top-up {$payment->id}.",
                ));
            }
        } elseif (
            $payment->status === SmsTopUpPaymentStatus::PaymentReversed
            && $previousStatus === SmsTopUpPaymentStatus::PaymentPaid
        ) {
            $this->wallets->reverseTopUp($payment);
            $this->notifier->outstandingCredit($payment->account);
        } elseif (
            $payment->kind === SmsTopUpKind::Automatic
            && in_array($payment->status, [
                SmsTopUpPaymentStatus::PaymentFailed,
                SmsTopUpPaymentStatus::PaymentCancelled,
                SmsTopUpPaymentStatus::PaymentExpired,
            ], true)
        ) {
            $payment->wallet->forceFill(['auto_top_up_suspended_at' => now()])->save();
            $this->notifier->automaticTopUpFailed($payment->account, $payment->failure_reason);
        }

        return $payment;
    }

    private function assertCallbackMatchesPayment(
        SmsTopUpPayment $payment,
        PaymentCallbackResult $callback,
    ): void {
        $references = array_filter([
            $payment->order_id,
            $payment->gateway_invoice_id,
            $payment->gateway_payment_id,
        ]);

        if (
            $callback->orderId !== ''
            && ! in_array($callback->orderId, $references, true)
            && ! in_array($callback->gatewayInvoiceId, $references, true)
        ) {
            throw new InvalidPaymentCallbackException('Callback order does not match SMS top-up payment.');
        }

        if ($callback->amountCents !== null && $callback->amountCents !== $payment->amount_cents) {
            throw new InvalidPaymentCallbackException('Callback amount does not match SMS top-up payment.');
        }

        if ($callback->currency !== null && strtoupper($callback->currency) !== $payment->currency) {
            throw new InvalidPaymentCallbackException('Callback currency does not match SMS top-up payment.');
        }
    }
}
