<?php

namespace App\Support\Sms;

use App\Models\SystemSetting;

class SmsServiceSettings
{
    public const EnabledKey = 'sms_service.enabled';

    public const TopUpPresetsKey = 'sms_service.top_up_presets_cents';

    public const OtpHourlyLimitKey = 'sms_service.otp_hourly_limit';

    public const OtpDailyLimitKey = 'sms_service.otp_daily_limit';

    public const ProviderLowBalanceThresholdKey = 'sms_service.provider_low_balance_threshold_cents';

    public const ProviderBalanceCentsKey = 'sms_service.provider_balance_cents';

    public const ProviderBalanceCurrencyKey = 'sms_service.provider_balance_currency';

    public const ProviderBalanceCheckedAtKey = 'sms_service.provider_balance_checked_at';

    public const ProviderBalanceErrorKey = 'sms_service.provider_balance_error';

    /**
     * @return array<int, int>
     */
    public function topUpPresetsCents(): array
    {
        $configured = json_decode(
            SystemSetting::stringValue(self::TopUpPresetsKey, '[5000,10000,20000]') ?? '[]',
            true,
        );

        if (! is_array($configured)) {
            return [5000, 10000, 20000];
        }

        $presets = collect($configured)
            ->filter(fn (mixed $amount): bool => is_numeric($amount) && (int) $amount > 0)
            ->map(fn (mixed $amount): int => (int) $amount)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $presets ?: [5000, 10000, 20000];
    }

    public function enabled(): bool
    {
        return filter_var(
            SystemSetting::stringValue(self::EnabledKey, '0'),
            FILTER_VALIDATE_BOOL,
        );
    }

    public function otpHourlyLimit(): int
    {
        return $this->positiveInteger(self::OtpHourlyLimitKey, 60);
    }

    public function otpDailyLimit(): int
    {
        return $this->positiveInteger(self::OtpDailyLimitKey, 300);
    }

    public function providerLowBalanceThresholdCents(): int
    {
        return max(0, (int) SystemSetting::stringValue(self::ProviderLowBalanceThresholdKey, '0'));
    }

    /**
     * @return array{amount_cents: int|null, currency: string|null, checked_at: string|null, error: string|null}
     */
    public function providerBalanceStatus(): array
    {
        $amount = SystemSetting::stringValue(self::ProviderBalanceCentsKey);

        return [
            'amount_cents' => is_numeric($amount) ? (int) $amount : null,
            'currency' => SystemSetting::stringValue(self::ProviderBalanceCurrencyKey),
            'checked_at' => SystemSetting::stringValue(self::ProviderBalanceCheckedAtKey),
            'error' => SystemSetting::stringValue(self::ProviderBalanceErrorKey),
        ];
    }

    public function clearProviderBalanceStatus(): void
    {
        SystemSetting::setValue(self::ProviderBalanceCentsKey, null);
        SystemSetting::setValue(self::ProviderBalanceCurrencyKey, null);
        SystemSetting::setValue(self::ProviderBalanceCheckedAtKey, null);
        SystemSetting::setValue(self::ProviderBalanceErrorKey, null);
    }

    /**
     * @param  array<int, int>  $presets
     */
    public function save(
        bool $enabled,
        array $presets,
        int $otpHourlyLimit,
        int $otpDailyLimit,
        int $providerLowBalanceThresholdCents,
    ): void {
        $presets = collect($presets)
            ->filter(fn (int $amount): bool => $amount > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        SystemSetting::setValue(self::EnabledKey, $enabled ? '1' : '0');
        SystemSetting::setValue(self::TopUpPresetsKey, json_encode($presets, JSON_THROW_ON_ERROR));
        SystemSetting::setValue(self::OtpHourlyLimitKey, (string) $otpHourlyLimit);
        SystemSetting::setValue(self::OtpDailyLimitKey, (string) $otpDailyLimit);
        SystemSetting::setValue(self::ProviderLowBalanceThresholdKey, (string) $providerLowBalanceThresholdCents);
    }

    private function positiveInteger(string $key, int $default): int
    {
        return max(1, (int) SystemSetting::stringValue($key, (string) $default));
    }
}
