<?php

namespace App\Support\SaasBilling;

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

        if (in_array($subscription->status, [
            SubscriptionStatus::Suspended,
            SubscriptionStatus::Cancelled,
            SubscriptionStatus::Expired,
        ], true)) {
            return false;
        }

        return $subscription->ends_at === null || $subscription->ends_at->isFuture();
    }

    public function shouldShowWarning(Account $account): bool
    {
        $subscription = $this->subscription($account);

        if (! $subscription) {
            return false;
        }

        return ! $this->canEditStudio($account)
            || $subscription->status === SubscriptionStatus::PastDue;
    }

    public function isExpired(Account $account): bool
    {
        return ! $this->canEditStudio($account);
    }

    private function subscription(Account $account): ?AccountSubscription
    {
        if (! $account->relationLoaded('subscription')) {
            $account->loadMissing('subscription.plan');
        }

        return $account->subscription;
    }
}
