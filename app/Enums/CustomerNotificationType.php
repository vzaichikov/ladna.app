<?php

namespace App\Enums;

enum CustomerNotificationType: string
{
    case ClassReminder = 'class_reminder';
    case ClassCancellation = 'class_cancellation';

    public function labelKey(): string
    {
        return 'app.customer_notification_type_'.$this->value;
    }
}
