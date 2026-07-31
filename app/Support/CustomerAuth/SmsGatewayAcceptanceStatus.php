<?php

namespace App\Support\CustomerAuth;

enum SmsGatewayAcceptanceStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
