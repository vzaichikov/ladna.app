<?php

namespace App\Support\Promotions;

final readonly class PromotionQuote
{
    /**
     * @param  array<int|string, int>  $lineDiscounts
     */
    public function __construct(
        public int $subtotalCents,
        public int $eligibleSubtotalCents,
        public int $discountCents,
        public int $totalCents,
        public array $lineDiscounts,
    ) {}
}
