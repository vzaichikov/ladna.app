<?php

namespace App\Support\Sms;

use App\Models\Account;

class AccountSmsPricing
{
    public function segmentPriceCents(Account $account): ?int
    {
        $account->loadMissing('subscription.plan');

        $price = $account->subscription?->plan?->sms_segment_price_cents;

        return $price === null ? null : (int) $price;
    }

    public function isAvailable(Account $account): bool
    {
        return $this->segmentPriceCents($account) !== null;
    }

    public function isFree(Account $account): bool
    {
        return $this->segmentPriceCents($account) === 0;
    }
}
