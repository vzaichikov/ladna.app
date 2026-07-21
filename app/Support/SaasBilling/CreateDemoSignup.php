<?php

namespace App\Support\SaasBilling;

use App\Models\AccountSignupRequest;
use App\Models\AccountSubscription;
use App\Models\SubscriptionPlan;
use LogicException;

class CreateDemoSignup
{
    /**
     * The paid demo signup was retired. Existing signup and payment rows remain
     * readable so historical callbacks and audit screens keep working.
     *
     * @param  array<string, mixed>  $validated
     */
    public function execute(array $validated, SubscriptionPlan $plan): never
    {
        throw new LogicException('Paid demo signup is retired. Use explicit billing-v2 enrollment and a free trial.');
    }

    public function createPayment(
        AccountSignupRequest $signup,
        ?AccountSubscription $subscription = null,
        ?SubscriptionPlan $plan = null,
        ?string $orderId = null,
    ): never {
        throw new LogicException('New demo-initial payments are retired.');
    }
}
