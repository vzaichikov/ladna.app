<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\SubscriptionBillingMode;
use App\Enums\SubscriptionPlanType;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use LogicException;

class AssignAccountPromoTariff
{
    public function execute(Account $account, SubscriptionPlan $promoPlan): AccountSubscription
    {
        if ($account->isReadOnlyDemo()) {
            throw new LogicException('The protected demo account cannot change tariff.');
        }

        if (
            ! $promoPlan->is_active
            || $promoPlan->plan_type !== SubscriptionPlanType::Promo
            || $promoPlan->public_signup_enabled
            || $promoPlan->requires_recurring_payment
        ) {
            throw new LogicException('Only an active non-public promo tariff may be assigned.');
        }

        return DB::transaction(function () use ($account, $promoPlan): AccountSubscription {
            $lockedAccount = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $subscription = $lockedAccount->subscription()->lockForUpdate()->first();

            if ($subscription?->payments()
                ->whereIn('status', [
                    AccountSubscriptionPaymentStatus::PaymentStarted->value,
                    AccountSubscriptionPaymentStatus::PaymentPending->value,
                ])
                ->exists()) {
                throw new LogicException('Wait for the current subscription payment to finish before granting a promo tariff.');
            }

            return $lockedAccount->subscription()->updateOrCreate(
                ['account_id' => $lockedAccount->id],
                [
                    'subscription_plan_id' => $promoPlan->id,
                    'subscription_price_version_id' => null,
                    'pending_subscription_price_version_id' => null,
                    'pending_tariff_change_at' => null,
                    'status' => SubscriptionStatus::Active,
                    'billing_mode' => SubscriptionBillingMode::Legacy,
                    'billing_interval_v2' => null,
                    'billable_location_count' => null,
                    'grace_ends_at' => null,
                    'cancel_at_period_end' => false,
                    'cancellation_requested_at' => null,
                    'renewal_attempts' => 0,
                    'next_retry_at' => null,
                    'started_at' => $subscription?->started_at ?? now(),
                    'ends_at' => null,
                    'next_payment_at' => null,
                    'auto_renew_enabled' => false,
                    'cancelled_at' => null,
                ],
            )->refresh();
        });
    }
}
