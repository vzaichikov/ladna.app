<?php

namespace App\Enums;

enum FestivalEditionPurchaseStatus: string
{
    case PaymentStarted = 'payment_started';
    case PaymentPending = 'payment_pending';
    case Available = 'available';
    case Redeemed = 'redeemed';
    case PaymentFailed = 'payment_failed';
    case PaymentCancelled = 'payment_cancelled';
    case PaymentExpired = 'payment_expired';
    case PaymentReversed = 'payment_reversed';

    public function grantsEntitlement(): bool
    {
        return in_array($this, [self::Available, self::Redeemed], true);
    }
}
