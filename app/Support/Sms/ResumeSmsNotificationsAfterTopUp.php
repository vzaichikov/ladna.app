<?php

namespace App\Support\Sms;

use App\Enums\CustomerNotificationStatus;
use App\Models\Account;
use App\Models\CustomerNotification;

class ResumeSmsNotificationsAfterTopUp
{
    public function execute(Account $account): int
    {
        return CustomerNotification::query()
            ->whereBelongsTo($account)
            ->where('status', CustomerNotificationStatus::WaitingForSmsCredit->value)
            ->update([
                'status' => CustomerNotificationStatus::Pending->value,
                'scheduled_send_at' => now(),
                'next_attempt_at' => null,
                'last_error' => 'sms_credit_restored_revalidation_required',
                'updated_at' => now(),
            ]);
    }
}
