<?php

namespace App\Actions\Payments;

use App\Enums\IntegrationProvider;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\MonopayGateway;
use App\Support\Payments\PaymentAmounts;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentGatewayException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ReconcileCustomerPurchasePayment
{
    public function __construct(
        private readonly MonopayGateway $monopay,
        private readonly CompleteCustomerPurchase $completeCustomerPurchase,
    ) {}

    public function execute(CustomerPurchase $purchase, bool $requireFinalStatus = false, bool $requireFreshStatus = false): CustomerPurchase
    {
        $purchase = CustomerPurchase::query()
            ->with('account')
            ->where('account_id', $purchase->account_id)
            ->findOrFail($purchase->id);

        if ($purchase->isPaid()
            || $purchase->payment_source !== CustomerPurchase::SourceOnlineCheckout
            || $purchase->account->isReadOnlyDemo()) {
            return $purchase;
        }

        if ($purchase->provider !== IntegrationProvider::Monopay->value) {
            return $purchase->expirePaymentWindow();
        }

        $invoiceId = $purchase->gateway_invoice_id;

        if (! is_string($invoiceId) || trim($invoiceId) === '') {
            return $purchase->expirePaymentWindow();
        }

        $setting = IntegrationSetting::forAccount($purchase->account)
            ->where('provider', IntegrationProvider::Monopay->value)
            ->where('is_enabled', true)
            ->first();

        $cacheKey = 'customer-purchase-payment-check:'.$purchase->account_id.':'.$purchase->id;

        $mustCheck = $requireFinalStatus || $requireFreshStatus;

        if (! $mustCheck && ! Cache::add($cacheKey, true, 15)) {
            return $purchase;
        }

        try {
            if (! $setting) {
                throw new PaymentGatewayException('Customer purchase payment integration is unavailable.');
            }

            if ($mustCheck) {
                Cache::put($cacheKey, true, 15);
            }

            $callback = $this->monopay->invoiceStatus($invoiceId, $setting);

            if ($callback->gatewayInvoiceId !== $invoiceId
                || $callback->orderId !== $purchase->order_id
                || $callback->amountCents !== $purchase->amount_cents
                || $callback->currency === null
                || (int) ($callback->payload['ccy'] ?? 0) !== PaymentAmounts::iso4217NumericCode($purchase->currency)
                || strtoupper($callback->currency) !== strtoupper($purchase->currency)) {
                throw new InvalidPaymentCallbackException('Monopay payment status does not match the customer purchase.');
            }

            if ($callback->status === PaymentCallbackStatus::Pending
                && ($requireFinalStatus || $purchase->status->isFinal())) {
                throw new PaymentGatewayException('The payment provider has not confirmed a final payment status.');
            }

            $completedPurchase = $this->completeCustomerPurchase->execute($purchase, $callback);

            if ($mustCheck
                && ! $completedPurchase->isPaid()
                && $callback->isOlderThan($completedPurchase->last_callback_payload)) {
                throw new PaymentGatewayException('The payment provider returned an obsolete payment status.');
            }

            return $completedPurchase;
        } catch (Throwable $exception) {
            report($exception);

            if ($mustCheck) {
                throw new PaymentGatewayException('Customer purchase payment status is unavailable.', previous: $exception);
            }
        }

        return $purchase->refresh();
    }
}
