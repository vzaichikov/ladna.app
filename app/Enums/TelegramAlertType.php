<?php

namespace App\Enums;

enum TelegramAlertType: string
{
    case TrainerAssignment = 'trainer_assignment';
    case OwnerAnnouncement = 'owner_announcement';
    case FoundersAnnouncement = 'founders_announcement';

    public function labelKey(): string
    {
        return 'app.telegram_alert_type_'.$this->value;
    }
}
