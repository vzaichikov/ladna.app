<?php

namespace App\Support\CustomerAuth;

use App\Enums\CustomerOtpSenderScope;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\CustomerAuthSetting;
use App\Models\IntegrationSetting;

class CustomerAuthAvailability
{
    public function settingsFor(Account $account): CustomerAuthSetting
    {
        return $account->customerAuthSetting()->first() ?: new CustomerAuthSetting([
            'account_id' => $account->id,
        ]);
    }

    public function methodsFor(Account $account): CustomerAuthMethodAvailability
    {
        $settings = $this->settingsFor($account);
        $turnstile = $this->turnstileSetting();

        return new CustomerAuthMethodAvailability(
            emailPassword: $settings->allow_email_password,
            otp: $settings->allow_otp && $turnstile !== null && $this->smsSettingFor($account, $settings) !== null,
            google: $settings->allow_google && $this->googleSetting() !== null,
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
        $scope = $settings->otp_sender_scope;
        $provider = $settings->otp_provider;

        $query = $scope === CustomerOtpSenderScope::Account
            ? IntegrationSetting::forAccount($account)
            : IntegrationSetting::platform();

        return $query
            ->where('category', IntegrationCategory::Messaging->value)
            ->where('is_enabled', true)
            ->when($provider, fn ($query) => $query->where('provider', $provider))
            ->orderByRaw("FIELD(provider, 'turbosms', 'smsclub', 'sendpulse')")
            ->first();
    }

    private function platformProvider(IntegrationProvider $provider): ?IntegrationSetting
    {
        return IntegrationSetting::query()
            ->platform()
            ->where('provider', $provider->value)
            ->where('scope_type', IntegrationScope::Platform->value)
            ->where('is_enabled', true)
            ->first();
    }
}
