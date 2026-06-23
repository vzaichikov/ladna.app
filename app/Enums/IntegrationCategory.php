<?php

namespace App\Enums;

enum IntegrationCategory: string
{
    case Payment = 'payment';
    case Fiscalization = 'fiscalization';
    case Messaging = 'messaging';
    case Authentication = 'authentication';

    public function labelKey(): string
    {
        return 'app.integration_category_'.$this->value;
    }
}
