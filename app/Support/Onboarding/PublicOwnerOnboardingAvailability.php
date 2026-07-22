<?php

namespace App\Support\Onboarding;

use App\Models\IntegrationSetting;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Support\CustomerAuth\CustomerAuthAvailability;

class PublicOwnerOnboardingAvailability
{
    public function __construct(private readonly CustomerAuthAvailability $customerAuthAvailability) {}

    public function isAvailable(): bool
    {
        return config('ladna.public_owner_onboarding_enabled')
            && config('ladna.saas_billing_v2_enabled')
            && $this->currentPriceVersion() !== null
            && (! $this->turnstileRequired() || $this->turnstileSetting() !== null)
            && $this->platformSmsSetting() !== null;
    }

    public function turnstileRequired(): bool
    {
        return ! (
            app()->environment('local')
            && config('ladna.public_owner_onboarding_turnstile_bypass')
        );
    }

    public function currentPriceVersion(): ?SubscriptionPriceVersion
    {
        if (! config('ladna.public_owner_onboarding_enabled') || ! config('ladna.saas_billing_v2_enabled')) {
            return null;
        }

        $plan = SubscriptionPlan::query()
            ->billingV2Assignable()
            ->publicSignup()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return $plan?->currentPriceVersion();
    }

    public function turnstileSetting(): ?IntegrationSetting
    {
        return $this->customerAuthAvailability->turnstileSetting();
    }

    public function platformSmsSetting(): ?IntegrationSetting
    {
        return $this->customerAuthAvailability->platformSmsSetting();
    }

    public function turnstileSiteKey(): ?string
    {
        if (! $this->turnstileRequired()) {
            return null;
        }

        $siteKey = $this->turnstileSetting()?->readableCredentials()['site_key'] ?? null;

        return is_string($siteKey) && $siteKey !== '' ? $siteKey : null;
    }
}
