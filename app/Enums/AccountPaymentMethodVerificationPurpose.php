<?php

namespace App\Enums;

enum AccountPaymentMethodVerificationPurpose: string
{
    case Subscription = 'subscription';
    case SmsTopUp = 'sms_top_up';
}
