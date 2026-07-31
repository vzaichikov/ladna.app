<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\CustomerAuth\SmsGatewayBalanceProvider;
use App\Support\CustomerAuth\SmsGatewayResolver;
use App\Support\Sms\SmsServiceSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('sms-service:check-provider-balance')]
#[Description('Check and store the central SMS provider balance')]
class CheckSmsProviderBalance extends Command
{
    public function handle(
        CustomerAuthAvailability $availability,
        SmsGatewayResolver $gateways,
        SmsServiceSettings $settings,
    ): int {
        $setting = $availability->platformSmsSetting();

        if (! $setting) {
            $this->storeFailure('The central SMS provider is not configured.');

            return self::FAILURE;
        }

        $gateway = $gateways->resolve($setting);

        if (! $gateway instanceof SmsGatewayBalanceProvider) {
            $this->storeFailure('The central SMS provider does not support balance checks.');

            return self::FAILURE;
        }

        $result = $gateway->fetchBalance();

        if (! $result->successful || $result->amount === null || $result->currency === null) {
            $this->storeFailure((string) $result->message);

            return self::FAILURE;
        }

        $amountCents = (int) round(((float) $result->amount) * 100);
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceCentsKey, (string) $amountCents);
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceCurrencyKey, $result->currency);
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceCheckedAtKey, now()->toIso8601String());
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceErrorKey, null);

        if (
            $result->currency === 'UAH'
            && $amountCents <= $settings->providerLowBalanceThresholdCents()
        ) {
            report(new \RuntimeException('The central SMS provider balance is below the configured threshold.'));
        }

        $this->info("Central SMS provider balance: {$result->amount} {$result->currency}.");

        return self::SUCCESS;
    }

    private function storeFailure(string $message): void
    {
        $message = Str::limit(strip_tags($message), 1000, '');
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceCheckedAtKey, now()->toIso8601String());
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceErrorKey, $message);
        report(new \RuntimeException($message));
        $this->error($message);
    }
}
