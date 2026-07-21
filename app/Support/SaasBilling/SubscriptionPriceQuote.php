<?php

namespace App\Support\SaasBilling;

use App\Enums\SubscriptionBillingInterval;

final readonly class SubscriptionPriceQuote
{
    /**
     * @param  list<SubscriptionPriceTierBreakdown>  $tierBreakdown
     */
    public function __construct(
        public string $currency,
        public SubscriptionBillingInterval $interval,
        public int $quantity,
        public array $tierBreakdown,
        public int $monthlySubtotalCents,
        public int $subtotalCents,
        public int $discountCents,
        public int $finalAmountCents,
        public int $annualDiscountPercent,
        public float $prorationFactor,
    ) {}

    /**
     * @return array{currency: string, interval: string, quantity: int, tier_breakdown: list<array{starts_at_location: int, ends_at_location: int|null, quantity: int, unit_price_cents: int, monthly_amount_cents: int, amount_cents: int}>, monthly_subtotal_cents: int, subtotal_cents: int, discount_cents: int, final_amount_cents: int, annual_discount_percent: int, proration_factor: float}
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'interval' => $this->interval->value,
            'quantity' => $this->quantity,
            'tier_breakdown' => array_map(
                fn (SubscriptionPriceTierBreakdown $tier): array => $tier->toArray(),
                $this->tierBreakdown,
            ),
            'monthly_subtotal_cents' => $this->monthlySubtotalCents,
            'subtotal_cents' => $this->subtotalCents,
            'discount_cents' => $this->discountCents,
            'final_amount_cents' => $this->finalAmountCents,
            'annual_discount_percent' => $this->annualDiscountPercent,
            'proration_factor' => $this->prorationFactor,
        ];
    }
}
