<?php

namespace App\Support\Sms;

use App\Enums\CustomerNotificationStatus;
use App\Enums\FestivalNotificationStatus;
use App\Models\Account;
use App\Models\CustomerNotification;
use App\Models\FestivalNotification;

class ResumeSmsNotificationsAfterTopUp
{
    public function execute(Account $account): int
    {
        $customerNotifications = CustomerNotification::query()
            ->whereBelongsTo($account)
            ->where('status', CustomerNotificationStatus::WaitingForSmsCredit->value)
            ->update([
                'status' => CustomerNotificationStatus::Pending->value,
                'scheduled_send_at' => now(),
                'next_attempt_at' => null,
                'last_error' => 'sms_credit_restored_revalidation_required',
                'updated_at' => now(),
            ]);

        $festivalNotifications = FestivalNotification::query()
            ->whereBelongsTo($account)
            ->where('status', FestivalNotificationStatus::WaitingForSmsCredit->value)
            ->update([
                'status' => FestivalNotificationStatus::Pending->value,
                'available_at' => now(),
                'failure_reason' => 'sms_credit_restored_revalidation_required',
                'updated_at' => now(),
            ]);

        return $customerNotifications + $festivalNotifications;
    }
}
