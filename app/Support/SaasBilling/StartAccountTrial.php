<?php

namespace App\Support\SaasBilling;

use App\Enums\SubscriptionBillingMode;
use App\Enums\SubscriptionPlanType;
use App\Enums\SubscriptionPriceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\SubscriptionPriceVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

class StartAccountTrial
{
    public function __construct(private readonly BillingPeriodCalculator $periods) {}

    public function execute(Account $account, SubscriptionPriceVersion $priceVersion, ?CarbonInterface $startsAt = null): AccountSubscription
    {
        if ($account->isReadOnlyDemo()) {
            throw new LogicException('The protected demo account cannot be enrolled in billing.');
        }

        $startsAt ??= now();
        $priceVersion->loadMissing('plan');

        if (
            $priceVersion->status !== SubscriptionPriceStatus::Published
            || ! $priceVersion->effective_at
            || $priceVersion->effective_at->isAfter($startsAt)
            || ! $priceVersion->plan?->is_active
            || $priceVersion->plan->plan_type !== SubscriptionPlanType::Standard
            || ! $priceVersion->plan->requires_recurring_payment
            || ! $priceVersion->plan->currentPriceVersion($startsAt)?->is($priceVersion)
        ) {
            throw new LogicException('Only an active, effective published price version may start a trial.');
        }

        return DB::transaction(function () use ($account, $priceVersion, $startsAt): AccountSubscription {
            $lockedAccount = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $subscription = $lockedAccount->subscription()->lockForUpdate()->first();

            if ($subscription?->usesLocationBilling() || $subscription?->trial_started_at !== null) {
                throw new LogicException('This account has already used its Ladna trial.');
            }

            $trialEndsAt = $this->periods->trialEnd($startsAt, $priceVersion->trial_days);
            $billableLocationCount = max(1, $lockedAccount->locations()->active()->count());

            return $lockedAccount->subscription()->updateOrCreate(
                ['account_id' => $lockedAccount->id],
                [
                    'subscription_plan_id' => $priceVersion->subscription_plan_id,
                    'subscription_price_version_id' => $priceVersion->id,
                    'status' => SubscriptionStatus::Trialing,
                    'billing_mode' => SubscriptionBillingMode::LocationV2,
                    'billing_interval_v2' => null,
                    'billable_location_count' => $billableLocationCount,
                    'trial_started_at' => $startsAt,
                    'trial_ends_at' => $trialEndsAt,
                    'billing_anchor_at' => $trialEndsAt,
                    'started_at' => $startsAt,
                    'ends_at' => $trialEndsAt,
                    'next_payment_at' => null,
                    'payment_provider' => null,
                    'provider_subscription_id' => null,
                    'provider_status' => null,
                    'auto_renew_enabled' => false,
                    'grace_ends_at' => null,
                    'cancel_at_period_end' => false,
                    'cancellation_requested_at' => null,
                    'renewal_attempts' => 0,
                    'next_retry_at' => null,
                    'cancelled_at' => null,
                ],
            )->refresh();
        });
    }
}
