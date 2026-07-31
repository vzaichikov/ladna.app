<?php

namespace App\Enums;

enum SmsSendingMode: string
{
    case Disabled = 'disabled';
    case LadnaService = 'ladna_service';
    case OwnGateway = 'own_gateway';
}
