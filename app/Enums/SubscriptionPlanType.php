<?php

namespace App\Enums;

enum SubscriptionPlanType: string
{
    case Demo = 'demo';
    case Promo = 'promo';
    case Standard = 'standard';
}
