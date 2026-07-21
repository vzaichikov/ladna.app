<?php

namespace App\Support\SaasBilling;

use App\Enums\SubscriptionBillingInterval;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceTier;
use App\Models\SubscriptionPriceVersion;
use App\Support\MoneyFormatter;

final readonly class PublicPricingPresenter
{
    private const MaximumLocationCount = 20;

    public function __construct(private SubscriptionPricingCalculator $pricingCalculator) {}

    /**
     * @return array{plan_name: string, trial_days: int, annual_discount_percent: int, minimum_location_count: int, maximum_location_count: int, quotes: array<int, array{location_label: string, monthly: array{total: string}, annual: array{total: string, discount: string}}>, tiers: list<array{starts_at_location: int, ends_at_location: int|null, unit_price: string}>}|null
     */
    public function current(): ?array
    {
        if (! config('ladna.saas_billing_v2_enabled')) {
            return null;
        }

        $plan = SubscriptionPlan::query()
            ->billingV2Assignable()
            ->publicSignup()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
        $priceVersion = $plan?->currentPriceVersion();

        if (! $priceVersion) {
            return null;
        }

        $priceVersion->setRelation('plan', $plan);

        $minimumLocationCount = (int) $priceVersion->tiers->min('starts_at_location');

        return [
            'plan_name' => $priceVersion->plan->name,
            'trial_days' => $priceVersion->trial_days,
            'annual_discount_percent' => $priceVersion->annual_discount_percent,
            'minimum_location_count' => $minimumLocationCount,
            'maximum_location_count' => self::MaximumLocationCount,
            'quotes' => $this->quotes($priceVersion, $minimumLocationCount),
            'tiers' => $priceVersion->tiers
                ->map(fn (SubscriptionPriceTier $tier): array => [
                    'starts_at_location' => $tier->starts_at_location,
                    'ends_at_location' => $tier->ends_at_location,
                    'unit_price' => MoneyFormatter::format($tier->unit_price_cents, $priceVersion->currency),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array{location_label: string, monthly: array{total: string}, annual: array{total: string, discount: string}}>
     */
    private function quotes(SubscriptionPriceVersion $priceVersion, int $minimumLocationCount): array
    {
        $quotes = [];

        for ($locationCount = $minimumLocationCount; $locationCount <= self::MaximumLocationCount; $locationCount++) {
            $monthlyQuote = $this->pricingCalculator->calculate(
                $priceVersion,
                $locationCount,
                SubscriptionBillingInterval::Monthly,
            );
            $annualQuote = $this->pricingCalculator->calculate(
                $priceVersion,
                $locationCount,
                SubscriptionBillingInterval::Annual,
            );

            $quotes[$locationCount] = [
                'location_label' => trans_choice('app.landing.pricing_location_count', $locationCount, ['count' => $locationCount]),
                'monthly' => [
                    'total' => MoneyFormatter::format($monthlyQuote->finalAmountCents, $monthlyQuote->currency),
                ],
                'annual' => [
                    'total' => MoneyFormatter::format($annualQuote->finalAmountCents, $annualQuote->currency),
                    'discount' => MoneyFormatter::format($annualQuote->discountCents, $annualQuote->currency),
                ],
            ];
        }

        return $quotes;
    }
}
