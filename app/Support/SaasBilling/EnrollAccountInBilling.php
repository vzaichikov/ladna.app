<?php

namespace App\Support\SaasBilling;

use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\SubscriptionPriceVersion;
use LogicException;

class EnrollAccountInBilling
{
    public function __construct(private readonly StartAccountTrial $startTrial) {}

    public function execute(Account $account, SubscriptionPriceVersion $priceVersion): AccountSubscription
    {
        if (! config('ladna.saas_billing_v2_enabled')) {
            throw new LogicException('Ladna billing v2 is disabled.');
        }

        return $this->startTrial->execute($account, $priceVersion);
    }
}
