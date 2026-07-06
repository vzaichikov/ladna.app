<?php

namespace App\Enums;

enum TelegramAlertType: string
{
    case TrainerAssignment = 'trainer_assignment';

    public function labelKey(): string
    {
        return 'app.telegram_alert_type_'.$this->value;
    }
}
