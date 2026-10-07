<?php

namespace Tests\Unit;

use App\Enums\PromoCodeDiscountType;
use App\Support\Promotions\PromotionDiscountCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('quantityOfferCases')]
    public function test_quantity_offers_discount_the_last_free_units_of_complete_groups(
        int $buyQuantity,
        int $freeQuantity,
        int $quantity,
        array $expectedFreeUnitIndexes,
    ): void {
        $lineSubtotals = array_fill(0, $quantity, 100000);
        $quote = (new PromotionDiscountCalculator)->calculateQuantityDiscount(
            $lineSubtotals,
            [123 => array_keys($lineSubtotals)],
            $buyQuantity,
            $freeQuantity,
        );

        $this->assertSame($quantity * 100000, $quote->subtotalCents);
        $this->assertSame($quantity * 100000, $quote->eligibleSubtotalCents);
        $this->assertSame(count($expectedFreeUnitIndexes) * 100000, $quote->discountCents);
        $this->assertSame(($quantity - count($expectedFreeUnitIndexes)) * 100000, $quote->totalCents);
        $this->assertSame($expectedFreeUnitIndexes, array_keys(array_filter($quote->lineDiscounts)));
    }

    /** @return array<string, array{int, int, int, array<int, int>}> */
    public static function quantityOfferCases(): array
    {
        return [
            'nine do not qualify for nine plus one' => [9, 1, 9, []],
            'ten pay for nine' => [9, 1, 10, [9]],
            'eleven pay for ten' => [9, 1, 11, [9]],
            'twenty pay for eighteen' => [9, 1, 20, [9, 19]],
            'four do not qualify for three plus two' => [3, 2, 4, []],
            'five pay for three' => [3, 2, 5, [3, 4]],
            'six pay for four' => [3, 2, 6, [3, 4]],
            'ten pay for six' => [3, 2, 10, [3, 4, 8, 9]],
        ];
    }

    public function test_quantity_offers_count_plans_separately_and_preserve_unit_keys(): void
    {
        $quote = (new PromotionDiscountCalculator)->calculateQuantityDiscount(
            [10 => 1000, 11 => 2500, 12 => 1000, 13 => 2500, 14 => 1000, 15 => 2500, 16 => 4000],
            [100 => [10, 12, 14], 200 => [11, 13, 15]],
            2,
            1,
        );

        $this->assertSame([10 => 0, 12 => 0, 14 => 1000, 11 => 0, 13 => 0, 15 => 2500], $quote->lineDiscounts);
        $this->assertSame(14500, $quote->subtotalCents);
        $this->assertSame(10500, $quote->eligibleSubtotalCents);
        $this->assertSame(3500, $quote->discountCents);
        $this->assertSame(11000, $quote->totalCents);
    }

    public function test_quantity_offers_do_not_combine_different_plans_to_complete_a_group(): void
    {
        $quote = (new PromotionDiscountCalculator)->calculateQuantityDiscount(
            [0 => 1000, 1 => 1000, 2 => 1000],
            [100 => [0, 1], 200 => [2]],
            2,
            1,
        );

        $this->assertSame(0, $quote->discountCents);
        $this->assertSame(3000, $quote->totalCents);
    }

    public function test_fixed_cart_discount_is_applied_once_to_the_combined_eligible_subtotal(): void
    {
        $quote = (new PromotionDiscountCalculator)->calculate(
            [0 => 1000, 1 => 1000, 2 => 5000],
            [0, 1],
            PromoCodeDiscountType::Fixed,
            600,
        );

        $this->assertSame(600, $quote->discountCents);
        $this->assertSame([0 => 300, 1 => 300], $quote->lineDiscounts);
        $this->assertSame(6400, $quote->totalCents);
    }

    public function test_amount_calculation_rejects_quantity_promotion_without_group_configuration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PromotionDiscountCalculator)->calculate([0 => 1000], [0], PromoCodeDiscountType::BuyXGetY, 0);
    }

    public function test_quantity_calculation_rejects_a_zero_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PromotionDiscountCalculator)->calculateQuantityDiscount([0 => 1000], [100 => [0]], 0, 1);
    }
}
