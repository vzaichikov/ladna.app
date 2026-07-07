<?php

namespace App\Support\CustomerAuth;

use App\Enums\CustomerOtpSenderScope;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\CustomerAuthSetting;
use App\Models\IntegrationSetting;
use App\Support\IntegrationCatalog;
use Illuminate\Database\Eloquent\Builder;

class CustomerAuthAvailability
{
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
        $settings = $this->settingsFor($account);
        $turnstile = $this->turnstileSetting();

        return new CustomerAuthMethodAvailability(
            emailPassword: true,
            otp: $settings->allow_otp && $turnstile !== null && $this->smsSettingFor($account, $settings) !== null,
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
        return $settings->otp_sender_scope === CustomerOtpSenderScope::Account
            ? $this->accountSmsSetting($account, $settings->otp_provider)
            : $this->platformSmsSetting($settings->otp_provider);
    }

    public function customerSmsSettingFor(Account $account, CustomerAuthSetting $settings): ?IntegrationSetting
    {
        return $settings->customer_sms_sender_scope === CustomerOtpSenderScope::Account
            ? $this->accountSmsSetting($account, $settings->customer_sms_provider)
            : $this->platformSmsSetting($settings->customer_sms_provider);
    }

    public function platformSmsSetting(?string $provider = null): ?IntegrationSetting
    {
        return $this->configuredSmsSetting(IntegrationSetting::platform(), $provider);
    }

    public function accountSmsSetting(Account $account, ?string $provider = null): ?IntegrationSetting
    {
        return $this->configuredSmsSetting(IntegrationSetting::forAccount($account), $provider);
    }

    /**
     * @return array{google: bool, turnstile: bool, platform_sms: bool, account_sms: bool, customer_platform_sms: bool, customer_account_sms: bool, otp: bool, otp_enabled: bool}
     */
    public function readinessFor(Account $account): array
    {
        $settings = $this->settingsFor($account);
        $methods = $this->methodsFor($account);

        return [
            'google' => $this->googleSetting() !== null,
            'turnstile' => $this->turnstileSetting() !== null,
            'platform_sms' => $this->platformSmsSetting($settings->otp_provider) !== null,
            'account_sms' => $this->accountSmsSetting($account, $settings->otp_provider) !== null,
            'customer_platform_sms' => $this->platformSmsSetting($settings->customer_sms_provider) !== null,
            'customer_account_sms' => $this->accountSmsSetting($account, $settings->customer_sms_provider) !== null,
            'otp' => $methods->otp,
            'otp_enabled' => $settings->allow_otp,
        ];
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
