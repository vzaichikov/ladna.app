<?php

namespace App\Support\Fiscalization;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Support\IntegrationCatalog;
use Illuminate\Database\Eloquent\Builder;

class FiscalizationAvailability
{
    public function enabledForAccount(Account $account): bool
    {
        return $this->accountMethod($account) !== null;
    }

    public function enabledForPlatform(): bool
    {
        return $this->platformMethod() !== null;
    }

    public function accountMethod(Account $account): ?IntegrationSetting
    {
        if (! $this->ladnaFiscalizationEnabled(IntegrationScope::Account, $account)) {
            return null;
        }

        return $this->configuredMethod(IntegrationSetting::forAccount($account));
    }

    public function platformMethod(): ?IntegrationSetting
    {
        if (! $this->ladnaFiscalizationEnabled(IntegrationScope::Platform)) {
            return null;
        }

        return $this->configuredMethod(IntegrationSetting::platform());
    }

    public function methodForPayment(CustomerPurchase|AccountSubscriptionPayment $payment): ?IntegrationSetting
    {
        if ($payment instanceof CustomerPurchase) {
            $account = $payment->relationLoaded('account')
                ? $payment->account
                : $payment->account()->first();

            return $account ? $this->accountMethod($account) : null;
        }

        return $this->platformMethod();
    }

    private function ladnaFiscalizationEnabled(IntegrationScope $scope, ?Account $account = null): bool
    {
        $query = $scope === IntegrationScope::Account && $account
            ? IntegrationSetting::forAccount($account)
            : IntegrationSetting::platform();

        return $query
            ->where('provider', IntegrationProvider::LadnaFiscalization->value)
            ->where('category', IntegrationCategory::Fiscalization->value)
            ->where('is_enabled', true)
            ->exists();
    }

    private function configuredMethod(Builder $query): ?IntegrationSetting
    {
        return $query
            ->where('provider', IntegrationProvider::Checkbox->value)
            ->where('category', IntegrationCategory::Fiscalization->value)
            ->where('is_enabled', true)
            ->orderBy('provider')
            ->get()
            ->first(function (IntegrationSetting $setting): bool {
                if ($setting->hasUnreadableCredentials()) {
                    return false;
                }

                return IntegrationCatalog::hasRequiredCredentials(
                    $setting->provider->value,
                    $setting->readableCredentials(),
                );
            });
    }
}
