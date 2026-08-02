<?php

namespace App\Enums;

enum CustomerNotificationChannel: string
{
    case Automatic = 'automatic';
    case Telegram = 'telegram';
    case Sms = 'sms';
}
