<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionPlanType;
use App\Enums\SubscriptionPriceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\SubscriptionPriceVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

class AssignAccountSubscriptionTariff
{
    public function __construct(
        private readonly BillingPeriodCalculator $periods,
        private readonly SendBillingLifecycleNotification $notifications,
    ) {}

    public function execute(
        Account $account,
        SubscriptionPriceVersion $targetPriceVersion,
        ?CarbonInterface $changedAt = null,
    ): AccountSubscription {
        if (! config('ladna.saas_billing_v2_enabled')) {
            throw new LogicException('Ladna billing v2 is disabled.');
        }

        if ($account->isReadOnlyDemo()) {
            throw new LogicException('The protected demo account cannot change tariff.');
        }

        $changedAt ??= now();
        $this->assertAssignable($targetPriceVersion, $changedAt);
        $notificationDate = null;

        $subscription = DB::transaction(function () use ($account, $targetPriceVersion, $changedAt, &$notificationDate): AccountSubscription {
            $lockedAccount = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $subscription = $lockedAccount->subscription()
                ->with(['plan', 'priceVersion', 'pendingPriceVersion.plan'])
                ->lockForUpdate()
                ->first();

            if (! $subscription?->usesLocationBilling()) {
                throw new LogicException('Only a billing-v2 subscription may change tariff.');
            }

            if ($subscription->status === SubscriptionStatus::Trialing) {
                if (! $subscription->trial_ends_at?->isFuture()) {
                    throw new LogicException('An expired trial cannot change tariff.');
                }

                $subscription->forceFill([
                    'subscription_plan_id' => $targetPriceVersion->subscription_plan_id,
                    'subscription_price_version_id' => $targetPriceVersion->id,
                    'pending_subscription_price_version_id' => null,
                    'pending_tariff_change_at' => null,
                ])->save();
                $notificationDate = $subscription->trial_ends_at;

                return $subscription->refresh();
            }

            if ($subscription->status !== SubscriptionStatus::Active) {
                throw new LogicException('Only a trialing or active subscription may change tariff.');
            }

            if ($subscription->cancel_at_period_end) {
                throw new LogicException('Resume automatic renewal before changing tariff.');
            }

            if (! $subscription->ends_at?->isFuture() || ! $subscription->billing_interval_v2) {
                throw new LogicException('The next renewal boundary is unavailable.');
            }

            if ($targetPriceVersion->is($subscription->priceVersion)) {
                $subscription->forceFill([
                    'pending_subscription_price_version_id' => null,
                    'pending_tariff_change_at' => null,
                ])->save();

                return $subscription->refresh();
            }

            if ($this->hasRenewalInProgress($subscription)) {
                throw new LogicException('Wait for the current renewal attempt to finish before changing tariff.');
            }

            $effectiveAt = $subscription->ends_at->copy();
            $noticeEndsAt = $changedAt->copy()->addDays(30);

            while ($effectiveAt->isBefore($noticeEndsAt)) {
                $effectiveAt = $this->periods->periodEnd($effectiveAt, $subscription->billing_interval_v2);
            }

            $subscription->forceFill([
                'pending_subscription_price_version_id' => $targetPriceVersion->id,
                'pending_tariff_change_at' => $effectiveAt,
            ])->save();
            $notificationDate = $effectiveAt;

            return $subscription->refresh();
        });

        if ($notificationDate) {
            $targetPriceVersion->loadMissing('plan');
            $this->notifications->execute(
                $subscription,
                'tariff_change',
                $changedAt,
                [
                    'date' => $notificationDate->timezone($account->timezone ?? config('app.timezone'))->format('d.m.Y'),
                    'plan' => $targetPriceVersion->plan?->name,
                ],
            );
        }

        return $subscription;
    }

    private function assertAssignable(SubscriptionPriceVersion $priceVersion, CarbonInterface $at): void
    {
        $priceVersion->loadMissing(['plan', 'tiers']);
        $plan = $priceVersion->plan;

        if (
            $priceVersion->status !== SubscriptionPriceStatus::Published
            || ! $priceVersion->effective_at
            || $priceVersion->effective_at->isAfter($at)
            || ! $plan?->is_active
            || $plan->plan_type !== SubscriptionPlanType::Standard
            || ! $plan->requires_recurring_payment
            || ! $plan->currentPriceVersion($at)?->is($priceVersion)
        ) {
            throw new LogicException('Only the current published price of an active paid tariff may be assigned.');
        }
    }

    private function hasRenewalInProgress(AccountSubscription $subscription): bool
    {
        return $subscription->payments()
            ->whereIn('payment_type', [
                AccountSubscriptionPaymentType::FullSubscription->value,
                AccountSubscriptionPaymentType::ManualRenewal->value,
                AccountSubscriptionPaymentType::AutoRenewal->value,
            ])
            ->whereIn('status', [
                AccountSubscriptionPaymentStatus::PaymentStarted->value,
                AccountSubscriptionPaymentStatus::PaymentPending->value,
            ])
            ->where('period_starts_at', '>=', $subscription->ends_at)
            ->exists();
    }
}
