<?php

namespace App\Enums;

enum CustomerNotificationStatus: string
{
    case Pending = 'pending';
    case WaitingForSmsCredit = 'waiting_for_sms_credit';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
}
