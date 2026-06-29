<?php

namespace App\Enums;

enum TelegramBotMode: string
{
    case Disabled = 'disabled';
    case Simple = 'simple';
    case AiAssisted = 'ai_assisted';

    public function labelKey(): string
    {
        return 'app.telegram_bot_mode_'.$this->value;
    }
}
