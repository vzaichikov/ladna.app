<?php

namespace App\Enums;

enum EmailRecipientKind: string
{
    case Customer = 'customer';
    case StudioOwner = 'studio_owner';
    case EventBuyer = 'event_buyer';

    public function labelKey(): string
    {
        return 'app.email_recipient_kind_'.$this->value;
    }
}
