<?php

namespace App\Enums;

enum SubscriptionPaymentMethodStatus: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Revoked = 'revoked';
    case Failed = 'failed';
}
