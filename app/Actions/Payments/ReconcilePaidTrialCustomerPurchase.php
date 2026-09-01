<?php

namespace App\Actions\Payments;

use App\Enums\CustomerPurchaseStatus;
use App\Enums\IntegrationProvider;
use App\Models\CustomerPurchase;
use App\Models\User;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentAmounts;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\TrialClassPassEligibility;
use Illuminate\Validation\ValidationException;

class ReconcilePaidTrialCustomerPurchase
{
    public function __construct(
        private readonly CompleteCustomerPurchase $completeCustomerPurchase,
        private readonly TrialClassPassEligibility $trialEligibility,
    ) {}

    public function execute(
        CustomerPurchase $purchase,
        PaymentCallbackResult $callback,
        User $actor,
        string $reason,
    ): CustomerPurchase {
        $this->assertAvailable($purchase, $callback, $actor, $reason);

        return $this->completeCustomerPurchase->execute(
            $purchase,
            $callback,
            trialExceptionActor: $actor,
            trialExceptionReason: trim($reason),
        );
    }

    public function assertAvailable(
        CustomerPurchase $purchase,
        PaymentCallbackResult $callback,
        User $actor,
        string $reason,
    ): void {
        $purchase->loadMissing(['account', 'customer', 'classPassPlan']);
        $reason = trim($reason);

        $this->assertAuthoritativePayment($purchase, $callback);

        if ($purchase->provider !== IntegrationProvider::Monopay->value
            || $purchase->payment_source !== CustomerPurchase::SourceOnlineCheckout
            || ! in_array($purchase->status, [CustomerPurchaseStatus::PaymentStarted, CustomerPurchaseStatus::PaymentPending], true)
            || $purchase->customer_class_pass_id !== null
            || $purchase->trial_eligibility_validated_at !== null
            || ! $purchase->classPassPlan?->is_trial) {
            throw ValidationException::withMessages([
                'purchase' => 'Only one unfulfilled legacy Monopay trial purchase can use this audited exception.',
            ]);
        }

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000
            || ! $this->trialEligibility->paidOnlineOverrideIsAvailable(
                $purchase->account,
                $purchase->customer,
                $purchase,
                $actor,
            )) {
            throw ValidationException::withMessages([
                'override_trial_eligibility' => __('app.trial_class_pass_override_unavailable'),
            ]);
        }
    }

    public function assertAuthoritativePayment(
        CustomerPurchase $purchase,
        PaymentCallbackResult $callback,
    ): void {
        $rawAmount = $callback->payload['amount'] ?? null;
        $rawCurrency = $callback->payload['ccy'] ?? null;

        if ($callback->status !== PaymentCallbackStatus::Paid
            || $callback->orderId !== $purchase->order_id
            || $callback->gatewayInvoiceId !== $purchase->gateway_invoice_id
            || $callback->amountCents !== $purchase->amount_cents
            || strtoupper((string) $callback->currency) !== strtoupper($purchase->currency)
            || ! is_int($rawAmount)
            || $rawAmount !== $purchase->amount_cents
            || ! is_int($rawCurrency)
            || $rawCurrency !== PaymentAmounts::iso4217NumericCode($purchase->currency)) {
            throw new InvalidPaymentCallbackException('Authoritative Monopay status does not exactly match the purchase.');
        }
    }
}
