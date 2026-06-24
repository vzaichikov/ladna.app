<?php

namespace App\Support\Payments;

use App\Enums\CustomerPurchaseStatus;

enum PaymentCallbackStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function purchaseStatus(): CustomerPurchaseStatus
    {
        return match ($this) {
            self::Paid => CustomerPurchaseStatus::PaymentPaid,
            self::Pending => CustomerPurchaseStatus::PaymentPending,
            self::Failed => CustomerPurchaseStatus::PaymentFailed,
            self::Cancelled => CustomerPurchaseStatus::PaymentCancelled,
            self::Expired => CustomerPurchaseStatus::PaymentExpired,
        };
    }
}
