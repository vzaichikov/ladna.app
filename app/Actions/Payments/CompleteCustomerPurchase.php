<?php

namespace App\Actions\Payments;

use App\Actions\IssueCustomerClassPass;
use App\Enums\CustomerPurchaseStatus;
use App\Models\CustomerPurchase;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Support\Facades\DB;

class CompleteCustomerPurchase
{
    public function __construct(
        private readonly IssueCustomerClassPass $issueCustomerClassPass,
        private readonly TransactionalMailDispatcher $mailDispatcher,
    ) {}

    public function execute(CustomerPurchase $purchase, PaymentCallbackResult $callback): CustomerPurchase
    {
        $previousStatus = null;

        $completedPurchase = DB::transaction(function () use ($purchase, $callback, &$previousStatus): CustomerPurchase {
            $lockedPurchase = CustomerPurchase::query()
                ->with(['account', 'customer', 'classPassPlan', 'customerClassPass'])
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();
            $previousStatus = $lockedPurchase->getRawOriginal('status');

            if ($lockedPurchase->isPaid()) {
                return $lockedPurchase;
            }

            $this->assertCallbackMatchesPurchase($lockedPurchase, $callback);

            if ($callback->status === PaymentCallbackStatus::Paid) {
                if (! $lockedPurchase->classPassPlan) {
                    throw new InvalidPaymentCallbackException('Class pass plan is no longer available.');
                }

                $customerClassPass = $lockedPurchase->customerClassPass;

                if (! $customerClassPass) {
                    $customerClassPass = $this->issueCustomerClassPass->execute(
                        $lockedPurchase->account,
                        $lockedPurchase->customer,
                        $lockedPurchase->classPassPlan,
                        source: 'online_payment',
                        purchasedAt: $callback->paidAt,
                        snapshot: [
                            'plan_name' => $lockedPurchase->plan_name,
                            'plan_slug' => $lockedPurchase->plan_slug,
                            'price_cents' => $lockedPurchase->amount_cents,
                            'currency' => $lockedPurchase->currency,
                            'sessions_count' => $lockedPurchase->sessions_count,
                            'validity_days' => $lockedPurchase->validity_days,
                            'total_validity_days' => $lockedPurchase->total_validity_days,
                        ],
                    );
                }

                $lockedPurchase->forceFill([
                    'customer_class_pass_id' => $customerClassPass->id,
                    'status' => CustomerPurchaseStatus::PaymentPaid,
                    'gateway_invoice_id' => $callback->gatewayInvoiceId ?? $lockedPurchase->gateway_invoice_id,
                    'gateway_payment_id' => $callback->gatewayPaymentId ?? $lockedPurchase->gateway_payment_id,
                    'gateway_status' => $callback->gatewayStatus ?? $lockedPurchase->gateway_status,
                    'last_callback_payload' => $callback->payload,
                    'paid_at' => $callback->paidAt ?? now(),
                    'failure_reason' => null,
                ])->save();

                return $lockedPurchase->refresh();
            }

            $status = $callback->status->purchaseStatus();

            $lockedPurchase->forceFill([
                'status' => $status,
                'gateway_invoice_id' => $callback->gatewayInvoiceId ?? $lockedPurchase->gateway_invoice_id,
                'gateway_payment_id' => $callback->gatewayPaymentId ?? $lockedPurchase->gateway_payment_id,
                'gateway_status' => $callback->gatewayStatus ?? $lockedPurchase->gateway_status,
                'last_callback_payload' => $callback->payload,
                'failure_reason' => $callback->failureReason,
                'failed_at' => $status->isFinal() ? now() : $lockedPurchase->failed_at,
            ])->save();

            return $lockedPurchase->refresh();
        });

        if ($completedPurchase->status === CustomerPurchaseStatus::PaymentPaid && $previousStatus !== CustomerPurchaseStatus::PaymentPaid->value) {
            $completedPurchase->loadMissing('customerClassPass');

            if ($completedPurchase->customerClassPass) {
                $this->mailDispatcher->customerClassPassIssued($completedPurchase->customerClassPass);
            }
        } elseif ($completedPurchase->status->isFinal() && $previousStatus !== $completedPurchase->status->value) {
            $this->mailDispatcher->customerPurchaseFailed($completedPurchase);
        }

        return $completedPurchase;
    }

    private function assertCallbackMatchesPurchase(CustomerPurchase $purchase, PaymentCallbackResult $callback): void
    {
        if ($callback->orderId !== $purchase->order_id) {
            throw new InvalidPaymentCallbackException('Callback order does not match purchase.');
        }

        if ($callback->amountCents !== null && $callback->amountCents !== $purchase->amount_cents) {
            throw new InvalidPaymentCallbackException('Callback amount does not match purchase.');
        }

        if ($callback->currency !== null && strtoupper($callback->currency) !== strtoupper($purchase->currency)) {
            throw new InvalidPaymentCallbackException('Callback currency does not match purchase.');
        }
    }
}
