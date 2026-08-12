<?php

namespace App\Enums;

enum FestivalNotificationStatus: string
{
    case Pending = 'pending';
    case WaitingForSmsCredit = 'waiting_for_sms_credit';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
