<?php

namespace App\Support\Payments;

use Illuminate\Support\Carbon;

class PaymentCheckoutRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly string $description,
        public readonly ?string $buyerName,
        public readonly ?string $buyerEmail,
        public readonly ?string $buyerPhone,
        public readonly string $locale,
        public readonly string $returnUrl,
        public readonly string $callbackUrl,
        public readonly Carbon $expiresAt,
        public readonly bool $preferIframe = false,
        public readonly ?int $validitySeconds = null,
    ) {}
}
