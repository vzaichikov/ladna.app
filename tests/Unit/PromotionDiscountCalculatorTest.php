<?php

namespace Tests\Unit;

use App\Enums\PromoCodeDiscountType;
use App\Support\Promotions\PromotionDiscountCalculator;
use PHPUnit\Framework\TestCase;

class PromotionDiscountCalculatorTest extends TestCase
{
    public function test_fixed_discount_is_capped_at_the_eligible_subtotal(): void
    {
        $quote = (new PromotionDiscountCalculator)->calculate(
            ['eligible' => 1000, 'regular' => 5000],
            ['eligible'],
            PromoCodeDiscountType::Fixed,
            2500,
        );

        $this->assertSame(6000, $quote->subtotalCents);
        $this->assertSame(1000, $quote->eligibleSubtotalCents);
        $this->assertSame(1000, $quote->discountCents);
        $this->assertSame(5000, $quote->totalCents);
        $this->assertSame(['eligible' => 1000], $quote->lineDiscounts);
    }

    public function test_percentage_rounding_and_line_allocation_are_deterministic(): void
    {
        $quote = (new PromotionDiscountCalculator)->calculate(
            [20 => 1, 10 => 1, 30 => 1],
            [20, 10, 30],
            PromoCodeDiscountType::Percent,
            50,
        );

        $this->assertSame(2, $quote->discountCents);
        $this->assertSame([20 => 1, 10 => 1, 30 => 0], $quote->lineDiscounts);
        $this->assertSame($quote->discountCents, array_sum($quote->lineDiscounts));
    }
}
