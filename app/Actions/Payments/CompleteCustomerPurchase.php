<?php

namespace App\Actions\Payments;

use App\Actions\IssueCustomerClassPass;
use App\Actions\RecordStudioCashEntry;
use App\Enums\CustomerPurchaseStatus;
use App\Models\CustomerPurchase;
use App\Models\StudioCashEntry;
use App\Models\User;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteCustomerPurchase
{
    public function __construct(
        private readonly IssueCustomerClassPass $issueCustomerClassPass,
        private readonly TransactionalMailDispatcher $mailDispatcher,
        private readonly FiscalReceiptService $fiscalReceipts,
        private readonly RecordStudioCashEntry $cashEntries,
    ) {}

    public function execute(
        CustomerPurchase $purchase,
        PaymentCallbackResult $callback,
        ?User $trialExceptionActor = null,
        ?string $trialExceptionReason = null,
    ): CustomerPurchase {
        $previousStatus = null;

        $completedPurchase = DB::transaction(function () use ($purchase, $callback, $trialExceptionActor, $trialExceptionReason, &$previousStatus): CustomerPurchase {
            $lockedPurchase = CustomerPurchase::query()
                ->with(['account', 'customer', 'classPassPlan', 'customerClassPass', 'location', 'actor', 'items.classPassPlan', 'items.customerClassPass'])
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();
            $previousStatus = $lockedPurchase->getRawOriginal('status');

            $this->assertCallbackMatchesPurchase($lockedPurchase, $callback);

            if ($lockedPurchase->isPaid()) {
                return $lockedPurchase;
            }

            if ($callback->isOlderThan($lockedPurchase->last_callback_payload)) {
                return $lockedPurchase;
            }

            if ($callback->status === PaymentCallbackStatus::Paid) {
                if ($lockedPurchase->items->isNotEmpty()) {
                    return $this->completeCart($lockedPurchase, $callback);
                }
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
                            'price_cents' => $lockedPurchase->subtotal_cents ?? $lockedPurchase->amount_cents,
                            'currency' => $lockedPurchase->currency,
                            'sessions_count' => $lockedPurchase->sessions_count,
                            'validity_days' => $lockedPurchase->validity_days,
                            'total_validity_days' => $lockedPurchase->total_validity_days,
                            'available_from_time' => $lockedPurchase->classPassPlan->available_from_time,
                            'available_until_time' => $lockedPurchase->classPassPlan->available_until_time,
                            'allows_any_time' => $lockedPurchase->classPassPlan->allows_any_time,
                            'any_time_addon_price_cents' => $lockedPurchase->classPassPlan->any_time_addon_price_cents,
                        ],
                        issuedLocation: $lockedPurchase->location,
                        isPaid: true,
                        issuedBy: $trialExceptionReason !== null ? $trialExceptionActor : null,
                        trialEligibilityOverrideReason: $trialExceptionReason,
                        trialEligibilityAsOf: $lockedPurchase->trial_eligibility_validated_at,
                        trialEligibilityOverridePurchase: $trialExceptionReason !== null ? $lockedPurchase : null,
                    );
                }

                $customerClassPass->forceFill([
                    'is_paid' => true,
                    'price_cents' => $lockedPurchase->subtotal_cents ?? $lockedPurchase->amount_cents,
                    'paid_amount_cents' => $lockedPurchase->amount_cents,
                    'issued_location_id' => $customerClassPass->issued_location_id ?? $lockedPurchase->location_id,
                ])->save();

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

        $notify = function () use ($completedPurchase, $previousStatus): void {
            if ($completedPurchase->isPaid() && $previousStatus !== CustomerPurchaseStatus::PaymentPaid->value) {
                $completedPurchase->loadMissing(['customerClassPass', 'items.customerClassPass']);
                $passes = $completedPurchase->items->isNotEmpty()
                    ? $completedPurchase->items->pluck('customerClassPass')->filter()
                    : collect([$completedPurchase->customerClassPass])->filter();
                foreach ($passes as $pass) {
                    $this->mailDispatcher->customerClassPassIssued($pass);
                }
                try {
                    $this->fiscalReceipts->fiscalizeCustomerPurchase($completedPurchase);
                } catch (Throwable $exception) {
                    report($exception);
                }
            } elseif ($completedPurchase->status->isFinal() && $previousStatus !== $completedPurchase->status->value) {
                $this->mailDispatcher->customerPurchaseFailed($completedPurchase);
            }
        };
        if ($completedPurchase->hasItems()) {
            DB::afterCommit($notify);
        } else {
            $notify();
        }

        return $completedPurchase;
    }

    private function completeCart(CustomerPurchase $purchase, PaymentCallbackResult $callback): CustomerPurchase
    {
        foreach ($purchase->items as $item) {
            if (! $item->classPassPlan) {
                throw new InvalidPaymentCallbackException('Class pass plan is no longer available.');
            }
            $pass = $item->customerClassPass;
            if (! $pass) {
                $issuancePlan = clone $item->classPassPlan;
                $issuancePlan->is_trial = $item->is_trial;
                $pass = $this->issueCustomerClassPass->execute(
                    $purchase->account, $purchase->customer, $issuancePlan,
                    source: $purchase->payment_source === CustomerPurchase::SourceOnlineCheckout ? 'online_payment' : 'manual',
                    purchasedAt: $callback->paidAt ?? now(),
                    snapshot: [
                        'plan_name' => $item->plan_name, 'plan_slug' => $item->plan_slug,
                        'price_cents' => $item->subtotal_cents, 'currency' => $item->currency,
                        'sessions_count' => $item->sessions_count, 'validity_days' => $item->validity_days,
                        'total_validity_days' => $item->total_validity_days,
                        'available_from_time' => $item->available_from_time, 'available_until_time' => $item->available_until_time,
                        'allows_any_time' => $item->allows_any_time, 'any_time_addon_price_cents' => $item->any_time_addon_price_cents,
                    ],
                    issuedBy: $purchase->actor, issuedLocation: $purchase->location,
                    trialEligibilityAsOf: $purchase->trial_eligibility_validated_at,
                    notify: false,
                );
                $item->forceFill(['customer_class_pass_id' => $pass->id])->save();
            }
            $pass->forceFill(['is_paid' => true, 'paid_amount_cents' => $item->amount_cents])->save();
        }
        $purchase->forceFill([
            'status' => CustomerPurchaseStatus::PaymentPaid,
            'gateway_invoice_id' => $callback->gatewayInvoiceId ?? $purchase->gateway_invoice_id,
            'gateway_payment_id' => $callback->gatewayPaymentId ?? $purchase->gateway_payment_id,
            'gateway_status' => $callback->gatewayStatus ?? $purchase->gateway_status,
            'last_callback_payload' => $callback->payload, 'paid_at' => $callback->paidAt ?? now(), 'failure_reason' => null,
        ])->save();
        if ($purchase->isManualCashStudioPayment() && $purchase->amount_cents > 0) {
            $this->cashEntries->execute(
                $purchase->account, $purchase->location, StudioCashEntry::DirectionIn,
                $purchase->amount_cents, $purchase->paid_at, $purchase->actor, $purchase->plan_name,
                StudioCashEntry::PurposeCustomerPayment, currency: $purchase->currency, purchase: $purchase,
                sourceKey: 'purchase:'.$purchase->id.':cash-in',
            );
        }

        return $purchase->refresh()->load('items.customerClassPass');
    }

    private function assertCallbackMatchesPurchase(CustomerPurchase $purchase, PaymentCallbackResult $callback): void
    {
        if ($callback->orderId !== $purchase->order_id) {
            throw new InvalidPaymentCallbackException('Callback order does not match purchase.');
        }

        if ($callback->status === PaymentCallbackStatus::Paid && $purchase->hasItems()
            && ($callback->amountCents === null || $callback->currency === null)) {
            throw new InvalidPaymentCallbackException('Paid cart callback must include amount and currency.');
        }

        if ($callback->amountCents !== null && $callback->amountCents !== $purchase->amount_cents) {
            throw new InvalidPaymentCallbackException('Callback amount does not match purchase.');
        }

        if ($callback->currency !== null && strtoupper($callback->currency) !== strtoupper($purchase->currency)) {
            throw new InvalidPaymentCallbackException('Callback currency does not match purchase.');
        }
    }
}
