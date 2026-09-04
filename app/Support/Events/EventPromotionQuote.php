<?php

namespace App\Support\Events;

use App\Models\EventPromoCode;
use App\Support\Promotions\PromotionQuote;

final readonly class EventPromotionQuote
{
    public function __construct(
        public ?EventPromoCode $promoCode,
        public PromotionQuote $pricing,
        public ?string $emailHash,
        public ?string $phoneHash,
    ) {}
}
