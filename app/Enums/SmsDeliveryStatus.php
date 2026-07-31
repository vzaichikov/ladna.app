<?php

namespace App\Enums;

enum SmsDeliveryStatus: string
{
    case Pending = 'pending';
    case WaitingForCredit = 'waiting_for_credit';
    case Reserved = 'reserved';
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case Undelivered = 'undelivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Delivered,
            self::Undelivered,
            self::Failed,
            self::Cancelled,
        ], true);
    }
}
