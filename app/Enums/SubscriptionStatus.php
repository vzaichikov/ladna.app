<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
