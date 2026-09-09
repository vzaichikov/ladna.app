<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalPaymentStatus;
use App\Enums\IntegrationProvider;
use App\Models\FestivalPaymentAttempt;
use App\Models\IntegrationSetting;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\MonopayGateway;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentGatewayException;
use Throwable;

class ReconcileFestivalPaymentAttempt
{
    public function __construct(
        private readonly MonopayGateway $monopay,
        private readonly FestivalPaymentService $payments,
    ) {}

    public function supports(FestivalPaymentAttempt $attempt): bool
    {
        return $attempt->provider === IntegrationProvider::Monopay->value;
    }

    public function execute(FestivalPaymentAttempt $attempt): FestivalPaymentAttempt
    {
        $attempt = FestivalPaymentAttempt::query()
            ->with('account')
            ->whereKey($attempt->id)
            ->where('account_id', $attempt->account_id)
            ->firstOrFail();

        if (! $this->supports($attempt)
            || ! $attempt->account?->enable_festivals
            || $attempt->account->isReadOnlyDemo()) {
            throw new PaymentGatewayException('Festival payment status is unavailable.');
        }

        $invoiceId = $attempt->gateway_invoice_id
            ?: data_get($attempt->gateway_checkout_payload, 'response.invoiceId');
        if (! is_string($invoiceId) || trim($invoiceId) === '') {
            throw new PaymentGatewayException('Festival payment invoice is unavailable.');
        }

        $setting = IntegrationSetting::forAccount($attempt->account)
            ->where('provider', IntegrationProvider::Monopay->value)
            ->where('is_enabled', true)
            ->first();
        if (! $setting) {
            throw new PaymentGatewayException('Festival payment integration is unavailable.');
        }

        try {
            $callback = $this->monopay->invoiceStatus($invoiceId, $setting);
        } catch (Throwable $exception) {
            throw new PaymentGatewayException('Festival payment status is unavailable.', previous: $exception);
        }

        if ($callback->gatewayInvoiceId !== $invoiceId
            || $callback->orderId !== $attempt->order_id
            || $callback->amountCents !== $attempt->amount_cents
            || $callback->currency === null
            || strtoupper($callback->currency) !== strtoupper($attempt->currency)) {
            throw new InvalidPaymentCallbackException('Festival payment status does not match the payment attempt.');
        }

        if ($callback->status === PaymentCallbackStatus::Pending && $attempt->status !== FestivalPaymentStatus::Pending) {
            throw new PaymentGatewayException('The payment provider has not confirmed a final payment status.');
        }

        return $this->payments->completeAttempt($attempt, $callback);
    }
}
