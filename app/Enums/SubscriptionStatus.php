<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
