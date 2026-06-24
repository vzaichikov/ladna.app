<?php

namespace App\Support\Payments;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Support\IntegrationCatalog;
use Illuminate\Support\Collection;

class PaymentGatewayRegistry
{
    public function __construct(
        private readonly MonopayGateway $monopay,
        private readonly LiqPayGateway $liqpay,
        private readonly WayForPayGateway $wayforpay,
    ) {}

    public function get(string|IntegrationProvider $provider): PaymentGateway
    {
        $value = $provider instanceof IntegrationProvider ? $provider->value : $provider;

        return match ($value) {
            IntegrationProvider::Monopay->value => $this->monopay,
            IntegrationProvider::Liqpay->value => $this->liqpay,
            IntegrationProvider::Wayforpay->value => $this->wayforpay,
            default => throw new PaymentGatewayException('Unsupported payment provider.'),
        };
    }

    /**
     * @return array<int, string>
     */
    public function supportedProviderValues(): array
    {
        return [
            IntegrationProvider::Monopay->value,
            IntegrationProvider::Liqpay->value,
            IntegrationProvider::Wayforpay->value,
        ];
    }

    /**
     * @return Collection<int, IntegrationSetting>
     */
    public function availableSettingsFor(Account $account): Collection
    {
        return IntegrationSetting::forAccount($account)
            ->where('category', IntegrationCategory::Payment->value)
            ->where('is_enabled', true)
            ->orderBy('provider')
            ->get()
            ->filter(function (IntegrationSetting $setting): bool {
                $provider = $setting->provider->value;

                return in_array($provider, $this->supportedProviderValues(), true)
                    && IntegrationCatalog::hasRequiredCredentials($provider, $setting->readableCredentials());
            })
            ->values();
    }
}
