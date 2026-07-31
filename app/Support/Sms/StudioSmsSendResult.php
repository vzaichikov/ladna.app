<?php

namespace App\Support\Sms;

use App\Enums\SmsDeliveryStatus;
use App\Models\SmsDelivery;

class StudioSmsSendResult
{
    public function __construct(
        public readonly SmsDelivery $delivery,
        public readonly SmsDeliveryStatus $status,
        public readonly ?string $message = null,
    ) {}

    public function accepted(): bool
    {
        return in_array($this->status, [
            SmsDeliveryStatus::Accepted,
            SmsDeliveryStatus::Delivered,
        ], true);
    }

    public function waitingForCredit(): bool
    {
        return $this->status === SmsDeliveryStatus::WaitingForCredit;
    }

    public function unknown(): bool
    {
        return $this->status === SmsDeliveryStatus::Unknown;
    }
}
