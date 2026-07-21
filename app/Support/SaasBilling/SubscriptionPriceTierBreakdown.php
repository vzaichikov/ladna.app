<?php

namespace App\Support\SaasBilling;

final readonly class SubscriptionPriceTierBreakdown
{
    public function __construct(
        public int $startsAtLocation,
        public ?int $endsAtLocation,
        public int $quantity,
        public int $unitPriceCents,
        public int $monthlyAmountCents,
        public int $amountCents,
    ) {}

    /**
     * @return array{starts_at_location: int, ends_at_location: int|null, quantity: int, unit_price_cents: int, monthly_amount_cents: int, amount_cents: int}
     */
    public function toArray(): array
    {
        return [
            'starts_at_location' => $this->startsAtLocation,
            'ends_at_location' => $this->endsAtLocation,
            'quantity' => $this->quantity,
            'unit_price_cents' => $this->unitPriceCents,
            'monthly_amount_cents' => $this->monthlyAmountCents,
            'amount_cents' => $this->amountCents,
        ];
    }
}
