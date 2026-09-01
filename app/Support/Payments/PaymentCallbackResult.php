<?php

namespace App\Support\Payments;

use Illuminate\Support\Carbon;
use Throwable;

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
        public readonly ?Carbon $modifiedAt = null,
        public readonly array $payload = [],
    ) {}

    /** @param array<string, mixed>|null $previousPayload */
    public function isOlderThan(?array $previousPayload): bool
    {
        $previousModifiedDate = $previousPayload['modifiedDate'] ?? null;

        if (! $this->modifiedAt || ! is_string($previousModifiedDate) || $previousModifiedDate === '') {
            return false;
        }

        try {
            return $this->modifiedAt->isBefore(Carbon::parse($previousModifiedDate));
        } catch (Throwable) {
            return false;
        }
    }
}
