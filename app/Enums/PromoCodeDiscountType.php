<?php

namespace App\Enums;

enum PromoCodeDiscountType: string
{
    case Fixed = 'fixed';

    case Percent = 'percent';
}
