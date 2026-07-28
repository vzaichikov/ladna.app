<?php

namespace App\Enums;

enum EventOrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case PaidRequiresRefund = 'paid_requires_refund';
    case RefundRequired = 'refund_required';
    case Refunded = 'refunded';

    public function reservesInventory(): bool
    {
        return $this === self::Pending;
    }

    public function hasIssuedTickets(): bool
    {
        return in_array($this, [self::Paid, self::RefundRequired], true);
    }
}
