<?php

namespace App\Enums;

enum TelegramAlertType: string
{
    case TrainerAssignment = 'trainer_assignment';
    case TrainerClassCancellation = 'trainer_class_cancellation';
    case FestivalUpdate = 'festival_update';
    case SmsAccountIssue = 'sms_account_issue';
    case OwnerAnnouncement = 'owner_announcement';
    case FoundersAnnouncement = 'founders_announcement';

    public function labelKey(): string
    {
        return 'app.telegram_alert_type_'.$this->value;
    }
}
