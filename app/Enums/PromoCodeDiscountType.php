<?php

namespace App\Enums;

enum PromoCodeDiscountType: string
{
    case Fixed = 'fixed';

    case Percent = 'percent';

    case BuyXGetY = 'buy_x_get_y';
}
