<?php

namespace App\Support\SaasBilling;

use App\Enums\SubscriptionBillingInterval;
use App\Models\SubscriptionPriceTier;
use App\Models\SubscriptionPriceVersion;

final readonly class SubscriptionPricingCalculator
{
    public function __construct(private SubscriptionPriceTierValidator $tierValidator) {}

    public function calculate(
        SubscriptionPriceVersion $priceVersion,
        int $activeLocationCount,
        SubscriptionBillingInterval $interval,
        ?SubscriptionProrationPeriod $proration = null,
    ): SubscriptionPriceQuote {
        $priceVersion->loadMissing('tiers');
        $tiers = $priceVersion->tiers->sortBy('starts_at_location')->values();
        $this->tierValidator->assertValid($tiers);

        $quantity = max(1, $activeLocationCount);
        $intervalMultiplier = $interval === SubscriptionBillingInterval::Annual ? 12 : 1;
        $tierBreakdown = $tiers
            ->map(function (SubscriptionPriceTier $tier) use ($quantity, $intervalMultiplier): ?SubscriptionPriceTierBreakdown {
                if ($quantity < $tier->starts_at_location) {
                    return null;
                }

                $lastLocation = min($quantity, $tier->ends_at_location ?? $quantity);
                $tierQuantity = $lastLocation - $tier->starts_at_location + 1;
                $monthlyAmount = $tierQuantity * $tier->unit_price_cents;

                return new SubscriptionPriceTierBreakdown(
                    startsAtLocation: $tier->starts_at_location,
                    endsAtLocation: $tier->ends_at_location,
                    quantity: $tierQuantity,
                    unitPriceCents: $tier->unit_price_cents,
                    monthlyAmountCents: $monthlyAmount,
                    amountCents: $monthlyAmount * $intervalMultiplier,
                );
            })
            ->filter()
            ->values()
            ->all();
        $monthlySubtotal = array_sum(array_map(
            fn (SubscriptionPriceTierBreakdown $tier): int => $tier->monthlyAmountCents,
            $tierBreakdown,
        ));
        $subtotal = $monthlySubtotal * $intervalMultiplier;
        $annualDiscountPercent = $interval === SubscriptionBillingInterval::Annual
            ? $priceVersion->annual_discount_percent
            : 0;
        $discount = (int) round($subtotal * ($annualDiscountPercent / 100));
        $prorationFactor = $proration?->remainingFraction() ?? 1.0;
        $finalAmount = (int) round(($subtotal - $discount) * $prorationFactor);

        return new SubscriptionPriceQuote(
            currency: strtoupper($priceVersion->currency),
            interval: $interval,
            quantity: $quantity,
            tierBreakdown: $tierBreakdown,
            monthlySubtotalCents: $monthlySubtotal,
            subtotalCents: $subtotal,
            discountCents: $discount,
            finalAmountCents: $finalAmount,
            annualDiscountPercent: $annualDiscountPercent,
            prorationFactor: $prorationFactor,
        );
    }
}
