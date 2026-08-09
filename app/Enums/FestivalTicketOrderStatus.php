<?php

namespace App\Enums;

enum FestivalTicketOrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case PaidRequiresRefund = 'paid_requires_refund';
    case Refunded = 'refunded';
}
