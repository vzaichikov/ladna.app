<?php

namespace App\Enums;

enum TelegramChatAuthorizationStatus: string
{
    case Authorized = 'authorized';
    case Revoked = 'revoked';
}
