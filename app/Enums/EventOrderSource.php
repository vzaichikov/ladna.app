<?php

namespace App\Enums;

enum EventOrderSource: string
{
    case Checkout = 'checkout';
    case Manual = 'manual';
    case Entrance = 'entrance';
}
