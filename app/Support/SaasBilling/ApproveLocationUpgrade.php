<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Models\Location;
use LogicException;

class ApproveLocationUpgrade
{
    public function __construct(
        private readonly CreateBillingV2Payment $createPayment,
        private readonly ChargeAccountSubscription $chargeSubscription,
    ) {}

    public function execute(
        Account $account,
        Location $location,
        IntegrationSetting $setting,
        string $redirectUrl,
    ): ?string {
        if (! config('ladna.saas_billing_v2_enabled')) {
            throw new LogicException('Ladna billing v2 is disabled.');
        }

        $account->loadMissing(['subscription.paymentMethod', 'locations']);
        $subscription = $account->subscription;

        if ($location->account_id !== $account->id || ! $location->billing_activation_pending || $location->is_active) {
            throw new LogicException('This location is not awaiting billing approval.');
        }

        if (! $subscription?->usesLocationBilling() || $subscription->status !== SubscriptionStatus::Active) {
            throw new LogicException('Location upgrades require an active paid subscription.');
        }

        if (! $subscription->paymentMethod?->isActive()) {
            throw new LogicException('Verify a payment method before approving a location upgrade.');
        }

        $targetQuantity = max(1, $account->locations->where('is_active', true)->count() + 1);
        $payment = $this->createPayment->execute(
            $subscription,
            AccountSubscriptionPaymentType::LocationUpgrade,
            $targetQuantity,
            $location,
        );

        return $this->chargeSubscription->execute($payment, $setting, $redirectUrl, true);
    }
}
