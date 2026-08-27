<?php

namespace App\Enums;

enum TelegramBotProfile: string
{
    case Owner = 'owner';
    case Customer = 'customer';
    case Festival = 'festival';

    public function labelKey(): string
    {
        return 'app.telegram_bot_profile_'.$this->value;
    }
}
