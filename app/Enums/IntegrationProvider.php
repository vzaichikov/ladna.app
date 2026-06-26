<?php

namespace App\Enums;

enum IntegrationProvider: string
{
    case Monopay = 'monopay';
    case Liqpay = 'liqpay';
    case Wayforpay = 'wayforpay';
    case LadnaFiscalization = 'ladna_fiscalization';
    case Checkbox = 'checkbox';
    case Turbosms = 'turbosms';
    case Smsclub = 'smsclub';
    case Sendpulse = 'sendpulse';
    case MailDelivery = 'mail_delivery';
    case GoogleOauth = 'google_oauth';
    case CloudflareTurnstile = 'cloudflare_turnstile';
}
