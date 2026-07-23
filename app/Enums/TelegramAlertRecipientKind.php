<?php

namespace App\Enums;

enum TelegramAlertRecipientKind: string
{
    case Trainer = 'trainer';
    case StudioOwner = 'studio_owner';
    case FoundersGroup = 'founders_group';
}
