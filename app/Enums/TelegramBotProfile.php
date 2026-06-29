<?php

namespace App\Enums;

enum TelegramBotProfile: string
{
    case Owner = 'owner';
    case Customer = 'customer';

    public function labelKey(): string
    {
        return 'app.telegram_bot_profile_'.$this->value;
    }
}
