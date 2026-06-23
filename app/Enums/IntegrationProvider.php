<?php

namespace App\Enums;

enum IntegrationProvider: string
{
    case Monopay = 'monopay';
    case Liqpay = 'liqpay';
    case Wayforpay = 'wayforpay';
    case Checkbox = 'checkbox';
    case Turbosms = 'turbosms';
    case Smsclub = 'smsclub';
    case Sendpulse = 'sendpulse';
    case GoogleOauth = 'google_oauth';
    case CloudflareTurnstile = 'cloudflare_turnstile';
}
