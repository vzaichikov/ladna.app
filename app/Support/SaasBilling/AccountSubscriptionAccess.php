<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionPlanType;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;

class AccountSubscriptionAccess
{
    public function canUsePublicFeatures(Account $account): bool
    {
        return $this->canEditStudio($account);
    }

    public function canEditStudio(Account $account): bool
    {
        $subscription = $this->subscription($account);

        if (! $subscription) {
            return true;
        }

        if ($this->requiresInitialDemoPayment($account)) {
            return false;
        }

        if (in_array($subscription->status, [
            SubscriptionStatus::PendingPayment,
            SubscriptionStatus::Suspended,
            SubscriptionStatus::Cancelled,
            SubscriptionStatus::Expired,
        ], true)) {
            return false;
        }

        if ($subscription->usesLocationBilling() && $subscription->isInGracePeriod()) {
            return true;
        }

        return $subscription->ends_at === null || $subscription->ends_at->isFuture();
    }

    public function shouldShowWarning(Account $account): bool
    {
        $subscription = $this->subscription($account);

        if (! $subscription) {
            return false;
        }

        return $this->requiresInitialDemoPayment($account)
            || ! $this->canEditStudio($account)
            || $subscription->status === SubscriptionStatus::PastDue;
    }

    public function isExpired(Account $account): bool
    {
        return ! $this->canEditStudio($account);
    }

    public function requiresInitialDemoPayment(Account $account): bool
    {
        $subscription = $this->subscription($account);

        if ($subscription?->plan?->plan_type !== SubscriptionPlanType::Demo) {
            return false;
        }

        return ! $account->subscriptionPayments()
            ->where('payment_type', AccountSubscriptionPaymentType::DemoInitial->value)
            ->where('status', AccountSubscriptionPaymentStatus::PaymentPaid->value)
            ->exists();
    }

    private function subscription(Account $account): ?AccountSubscription
    {
        if (! $account->relationLoaded('subscription')) {
            $account->loadMissing('subscription.plan');
        }

        return $account->subscription;
    }
}
