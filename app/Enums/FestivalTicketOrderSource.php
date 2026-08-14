<?php

namespace App\Enums;

enum FestivalTicketOrderSource: string
{
    case Checkout = 'checkout';
    case Manual = 'manual';
}
