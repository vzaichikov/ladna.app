<?php

namespace App\Support\Promotions;

use Illuminate\Support\Str;

class PromotionCodeNormalizer
{
    public function normalize(?string $code): string
    {
        return Str::of($code ?? '')
            ->trim()
            ->upper()
            ->toString();
    }
}
