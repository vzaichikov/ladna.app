<?php

namespace App\Enums;

enum FestivalNotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Telegram = 'telegram';
}
