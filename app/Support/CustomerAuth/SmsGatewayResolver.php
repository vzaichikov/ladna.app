<?php

namespace App\Support\CustomerAuth;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;

class SmsGatewayResolver
{
    public function resolve(IntegrationSetting $setting): SmsGateway
    {
        $credentials = $setting->readableCredentials();

        return match ($setting->provider) {
            IntegrationProvider::Turbosms => new TurboSmsGateway($credentials),
            IntegrationProvider::Smsclub => new SmsClubGateway($credentials),
            IntegrationProvider::Sendpulse => new SendPulseSmsGateway($credentials),
            default => new LogSmsGateway,
        };
    }
}
