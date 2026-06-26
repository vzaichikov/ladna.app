<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Str;

class CreateAccountSubscriptionPayment
{
    public function execute(
        Account $account,
        SubscriptionPlan $plan,
        AccountSubscriptionPaymentType $type,
    ): AccountSubscriptionPayment {
        $account->loadMissing('subscription');
        $periodStart = $account->subscription?->ends_at?->isFuture()
            ? $account->subscription->ends_at
            : now();
        $periodEnd = $periodStart->copy()->addDays($plan->access_days ?? 30);

        return AccountSubscriptionPayment::create([
            'account_id' => $account->id,
            'account_subscription_id' => $account->subscription?->id,
            'subscription_plan_id' => $plan->id,
            'provider' => IntegrationProvider::Monopay->value,
            'payment_type' => $type,
            'order_id' => $this->orderId(),
            'amount_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'period_starts_at' => $periodStart,
            'period_ends_at' => $periodEnd,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
    }

    private function orderId(): string
    {
        return 'SAAS-'.now()->format('YmdHis').'-'.Str::upper(Str::random(10));
    }
}
