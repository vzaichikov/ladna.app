<?php

namespace App\Support\Festivals;

use App\Models\FestivalPromoCode;
use App\Support\Promotions\PromotionQuote;

final readonly class FestivalPromotionQuote
{
    public function __construct(
        public ?FestivalPromoCode $promoCode,
        public PromotionQuote $amounts,
        public ?string $emailHash,
        public ?string $phoneHash,
    ) {}
}
