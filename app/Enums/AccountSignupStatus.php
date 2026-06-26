<?php

namespace App\Enums;

enum AccountSignupStatus: string
{
    case PendingPayment = 'pending_payment';
    case PaymentStarted = 'payment_started';
    case PaymentPaid = 'payment_paid';
    case AccountCreated = 'account_created';
    case PaymentFailed = 'payment_failed';
    case PaymentCancelled = 'payment_cancelled';
    case PaymentExpired = 'payment_expired';
}
