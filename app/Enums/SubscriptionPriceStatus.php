<?php

namespace App\Enums;

enum SubscriptionPriceStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Retired = 'retired';
}
