<?php

namespace App\Enums;

enum IntegrationCategory: string
{
    case Payment = 'payment';
    case Fiscalization = 'fiscalization';
    case Messaging = 'messaging';

    public function labelKey(): string
    {
        return 'app.integration_category_'.$this->value;
    }
}
