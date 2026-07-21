<?php

namespace App\Enums;

enum SubscriptionBillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
}
