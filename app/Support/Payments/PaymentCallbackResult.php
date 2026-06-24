<?php

namespace App\Support\Payments;

use Illuminate\Support\Carbon;

class PaymentCallbackResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $orderId,
        public readonly PaymentCallbackStatus $status,
        public readonly ?string $gatewayStatus = null,
        public readonly ?int $amountCents = null,
        public readonly ?string $currency = null,
        public readonly ?string $gatewayInvoiceId = null,
        public readonly ?string $gatewayPaymentId = null,
        public readonly ?string $failureReason = null,
        public readonly ?Carbon $paidAt = null,
        public readonly array $payload = [],
    ) {}
}
