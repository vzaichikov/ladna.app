<?php

namespace App\Enums;

enum CustomerNotificationType: string
{
    case ClassReminder = 'class_reminder';

    public function labelKey(): string
    {
        return 'app.customer_notification_type_'.$this->value;
    }
}
