<?php

namespace App\Support\CustomerAuth;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Models\CustomerAuthSetting;
use App\Models\IntegrationSetting;
use App\Models\SystemSetting;
use App\Support\IntegrationCatalog;
use App\Support\Sms\AccountSmsPricing;
use App\Support\Sms\SmsServiceSettings;
use Illuminate\Database\Eloquent\Builder;

class CustomerAuthAvailability
{
    public function __construct(
        private readonly SmsServiceSettings $smsServiceSettings,
        private readonly AccountSmsPricing $smsPricing,
    ) {}

    public function settingsFor(Account $account): CustomerAuthSetting
    {
        $settings = $account->relationLoaded('customerAuthSetting')
            ? $account->getRelation('customerAuthSetting')
            : $account->customerAuthSetting()->first();

        return $settings ?: new CustomerAuthSetting([
            'account_id' => $account->id,
        ]);
    }

    public function methodsFor(Account $account): CustomerAuthMethodAvailability
    {
        if ($account->isReadOnlyDemo()) {
            return new CustomerAuthMethodAvailability(
                emailPassword: false,
                otp: false,
                google: false,
                turnstileSiteKey: null,
            );
        }

        $settings = $this->settingsFor($account);
        $turnstile = $this->turnstileSetting();

        return new CustomerAuthMethodAvailability(
            emailPassword: true,
            otp: $settings->allow_otp
                && $turnstile !== null
                && $this->smsSettingFor($account, $settings) !== null
                && $this->hasOtpCredit($account, $settings),
            google: $this->googleSetting() !== null,
            turnstileSiteKey: $turnstile?->readableCredentials()['site_key'] ?? null,
        );
    }

    public function googleSetting(): ?IntegrationSetting
    {
        return $this->platformProvider(IntegrationProvider::GoogleOauth);
    }

    public function turnstileSetting(): ?IntegrationSetting
    {
        return $this->platformProvider(IntegrationProvider::CloudflareTurnstile);
    }

    public function smsSettingFor(Account $account, CustomerAuthSetting $settings): ?IntegrationSetting
    {
        return match ($settings->sms_sending_mode) {
            SmsSendingMode::LadnaService => $this->smsServiceSettings->enabled()
                && $this->smsPricing->isAvailable($account)
                    ? $this->platformSmsSetting()
                    : null,
            SmsSendingMode::OwnGateway => $this->accountSmsSetting($account, $settings->sms_provider),
            SmsSendingMode::Disabled => null,
        };
    }

    public function customerSmsSettingFor(Account $account, CustomerAuthSetting $settings): ?IntegrationSetting
    {
        return $this->smsSettingFor($account, $settings);
    }

    public function platformSmsSetting(?string $provider = null): ?IntegrationSetting
    {
        $provider ??= SystemSetting::stringValue(SystemSetting::CentralSmsProviderKey);

        if (blank($provider)) {
            return null;
        }

        return $this->configuredSmsSetting(IntegrationSetting::platform(), $provider);
    }

    public function accountSmsSetting(Account $account, ?string $provider = null): ?IntegrationSetting
    {
        return $this->configuredSmsSetting(IntegrationSetting::forAccount($account), $provider);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function readinessFor(Account $account): array
    {
        $settings = $this->settingsFor($account);
        $methods = $this->methodsFor($account);
        $segmentPriceCents = $this->smsPricing->segmentPriceCents($account);
        $wallet = $account->smsWallet()->first();
        $spendableBalanceCents = $wallet?->spendableBalanceCents() ?? 0;
        $ladnaReady = $this->smsServiceSettings->enabled()
            && $segmentPriceCents !== null
            && $this->platformSmsSetting() !== null
            && (
                $segmentPriceCents === 0
                || (
                    $wallet !== null
                    && $wallet->outstanding_cents === 0
                    && $spendableBalanceCents >= $segmentPriceCents
                )
            );
        $ownGatewayReady = $this->accountSmsSetting($account, $settings->sms_provider) !== null;

        return [
            'google' => $this->googleSetting() !== null,
            'turnstile' => $this->turnstileSetting() !== null,
            'platform_sms' => $ladnaReady,
            'account_sms' => $ownGatewayReady,
            'customer_platform_sms' => $ladnaReady,
            'customer_account_sms' => $ownGatewayReady,
            'otp' => $methods->otp,
            'otp_enabled' => $settings->allow_otp,
            'sms_mode' => $settings->sms_sending_mode->value,
            'sms_service_enabled' => $this->smsServiceSettings->enabled(),
            'sms_segment_price_cents' => $segmentPriceCents,
            'sms_spendable_balance_cents' => $spendableBalanceCents,
            'sms_outstanding_cents' => $wallet?->outstanding_cents ?? 0,
            'sms_source_ready' => match ($settings->sms_sending_mode) {
                SmsSendingMode::LadnaService => $ladnaReady,
                SmsSendingMode::OwnGateway => $ownGatewayReady,
                SmsSendingMode::Disabled => false,
            },
        ];
    }

    private function assignedPlatformSmsSetting(?string $provider): ?IntegrationSetting
    {
        if (blank($provider)) {
            return null;
        }

        return $this->configuredSmsSetting(IntegrationSetting::platform(), $provider);
    }

    private function hasOtpCredit(Account $account, CustomerAuthSetting $settings): bool
    {
        if ($settings->sms_sending_mode !== SmsSendingMode::LadnaService) {
            return true;
        }

        $segmentPriceCents = $this->smsPricing->segmentPriceCents($account);

        if ($segmentPriceCents === null) {
            return false;
        }

        if ($segmentPriceCents === 0) {
            return true;
        }

        $wallet = $account->smsWallet()->first();

        return $wallet !== null
            && $wallet->outstanding_cents === 0
            && $wallet->spendableBalanceCents() >= $segmentPriceCents;
    }

    private function platformProvider(IntegrationProvider $provider): ?IntegrationSetting
    {
        return IntegrationSetting::query()
            ->platform()
            ->where('provider', $provider->value)
            ->where('scope_type', IntegrationScope::Platform->value)
            ->where('is_enabled', true)
            ->get()
            ->first(fn (IntegrationSetting $setting): bool => $this->settingIsConfigured($setting));
    }

    private function configuredSmsSetting(Builder $query, ?string $provider = null): ?IntegrationSetting
    {
        return $query
            ->where('category', IntegrationCategory::Messaging->value)
            ->where('is_enabled', true)
            ->when($provider, fn (Builder $query): Builder => $query->where('provider', $provider))
            ->orderByRaw("FIELD(provider, 'turbosms', 'smsclub', 'sendpulse')")
            ->get()
            ->first(fn (IntegrationSetting $setting): bool => $this->settingIsConfigured($setting));
    }

    private function settingIsConfigured(IntegrationSetting $setting): bool
    {
        if ($setting->hasUnreadableCredentials()) {
            return false;
        }

        return IntegrationCatalog::hasRequiredCredentials(
            $setting->provider->value,
            $setting->readableCredentials(),
        );
    }
}
