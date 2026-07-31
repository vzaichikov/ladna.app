<?php

namespace App\Support\CustomerAuth;

enum SmsGatewayDeliveryStatus: string
{
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case Undelivered = 'undelivered';
    case Unknown = 'unknown';
}
