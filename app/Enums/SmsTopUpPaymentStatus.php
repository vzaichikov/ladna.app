<?php

namespace App\Enums;

enum SmsTopUpPaymentStatus: string
{
    case PaymentStarted = 'payment_started';
    case PaymentPending = 'payment_pending';
    case PaymentPaid = 'payment_paid';
    case PaymentFailed = 'payment_failed';
    case PaymentCancelled = 'payment_cancelled';
    case PaymentExpired = 'payment_expired';
    case PaymentReversed = 'payment_reversed';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::PaymentPaid,
            self::PaymentFailed,
            self::PaymentCancelled,
            self::PaymentExpired,
            self::PaymentReversed,
        ], true);
    }
}
