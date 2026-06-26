<?php

namespace App\Enums;

enum AccountSubscriptionPaymentType: string
{
    case DemoInitial = 'demo_initial';
    case FullSubscription = 'full_subscription';
    case ManualRenewal = 'manual_renewal';
    case AutoRenewal = 'auto_renewal';
}
