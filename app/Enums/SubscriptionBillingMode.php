<?php

namespace App\Enums;

enum SubscriptionBillingMode: string
{
    case Legacy = 'legacy';
    case LocationV2 = 'location_v2';
}
