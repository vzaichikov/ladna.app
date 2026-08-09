<?php

namespace App\Enums;

enum FestivalChargeStatus: string
{
    case Pending = 'pending';
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PaidRequiresRefund = 'paid_requires_refund';
}
