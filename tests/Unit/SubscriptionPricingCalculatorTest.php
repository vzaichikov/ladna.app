<?php

namespace Tests\Unit;

use App\Enums\SubscriptionBillingInterval;
use App\Models\SubscriptionPriceTier;
use App\Models\SubscriptionPriceVersion;
use App\Support\SaasBilling\SubscriptionPriceTierValidator;
use App\Support\SaasBilling\SubscriptionPricingCalculator;
use App\Support\SaasBilling\SubscriptionProrationPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Tests\TestCase;

class SubscriptionPricingCalculatorTest extends TestCase
{
    private SubscriptionPricingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new SubscriptionPricingCalculator(new SubscriptionPriceTierValidator);
    }

    public function test_graduated_monthly_price_for_one_two_and_three_locations(): void
    {
        $priceVersion = $this->priceVersion();

        $oneLocation = $this->calculator->calculate($priceVersion, 1, SubscriptionBillingInterval::Monthly);
        $twoLocations = $this->calculator->calculate($priceVersion, 2, SubscriptionBillingInterval::Monthly);
        $threeLocations = $this->calculator->calculate($priceVersion, 3, SubscriptionBillingInterval::Monthly);

        $this->assertSame(90_000, $oneLocation->finalAmountCents);
        $this->assertSame(170_000, $twoLocations->finalAmountCents);
        $this->assertSame(250_000, $threeLocations->finalAmountCents);
        $this->assertSame(1, $threeLocations->tierBreakdown[0]->quantity);
        $this->assertSame(2, $threeLocations->tierBreakdown[1]->quantity);
    }

    public function test_annual_price_applies_ten_percent_discount(): void
    {
        $quote = $this->calculator->calculate(
            $this->priceVersion(),
            2,
            SubscriptionBillingInterval::Annual,
        );

        $this->assertSame(2_040_000, $quote->subtotalCents);
        $this->assertSame(204_000, $quote->discountCents);
        $this->assertSame(1_836_000, $quote->finalAmountCents);
        $this->assertSame(10, $quote->annualDiscountPercent);
    }

    public function test_zero_active_locations_still_bills_one_location(): void
    {
        $quote = $this->calculator->calculate(
            $this->priceVersion(),
            0,
            SubscriptionBillingInterval::Monthly,
        );

        $this->assertSame(1, $quote->quantity);
        $this->assertSame(90_000, $quote->finalAmountCents);
    }

    public function test_later_open_ended_tier_can_have_a_lower_unit_price(): void
    {
        $priceVersion = $this->priceVersion([
            [1, 1, 90_000],
            [2, 5, 80_000],
            [6, null, 70_000],
        ]);

        $quote = $this->calculator->calculate(
            $priceVersion,
            7,
            SubscriptionBillingInterval::Monthly,
        );

        $this->assertSame(550_000, $quote->finalAmountCents);
        $this->assertSame(2, $quote->tierBreakdown[2]->quantity);
        $this->assertSame(70_000, $quote->tierBreakdown[2]->unitPriceCents);
    }

    public function test_optional_proration_uses_the_exact_unused_period_fraction(): void
    {
        $period = new SubscriptionProrationPeriod(
            periodStartsAt: CarbonImmutable::parse('2026-01-01 00:00:00'),
            periodEndsAt: CarbonImmutable::parse('2026-01-31 00:00:00'),
            effectiveAt: CarbonImmutable::parse('2026-01-16 00:00:00'),
        );

        $quote = $this->calculator->calculate(
            $this->priceVersion(),
            1,
            SubscriptionBillingInterval::Monthly,
            $period,
        );

        $this->assertSame(0.5, $quote->prorationFactor);
        $this->assertSame(45_000, $quote->finalAmountCents);
    }

    public function test_tier_validator_rejects_gaps_and_a_closed_final_range(): void
    {
        $validator = new SubscriptionPriceTierValidator;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contiguous');

        $validator->assertValid([
            ['starts_at_location' => 1, 'ends_at_location' => 1, 'unit_price_cents' => 90_000],
            ['starts_at_location' => 3, 'ends_at_location' => 5, 'unit_price_cents' => 80_000],
        ]);
    }

    /**
     * @param  list<array{0: int, 1: int|null, 2: int}>|null  $tiers
     */
    private function priceVersion(?array $tiers = null): SubscriptionPriceVersion
    {
        $priceVersion = new SubscriptionPriceVersion([
            'version' => 1,
            'currency' => 'UAH',
            'trial_days' => 30,
            'annual_discount_percent' => 10,
        ]);
        $tierModels = array_map(
            fn (array $tier): SubscriptionPriceTier => new SubscriptionPriceTier([
                'starts_at_location' => $tier[0],
                'ends_at_location' => $tier[1],
                'unit_price_cents' => $tier[2],
            ]),
            $tiers ?? [
                [1, 1, 90_000],
                [2, null, 80_000],
            ],
        );
        $priceVersion->setRelation('tiers', new Collection($tierModels));

        return $priceVersion;
    }
}
