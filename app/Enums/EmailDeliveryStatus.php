<?php

namespace App\Enums;

enum EmailDeliveryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function labelKey(): string
    {
        return 'app.email_delivery_status_'.$this->value;
    }
}
