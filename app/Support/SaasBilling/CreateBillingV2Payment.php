<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionStatus;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPayment;
use App\Models\Location;
use App\Models\SubscriptionPriceVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use LogicException;

class CreateBillingV2Payment
{
    public function __construct(
        private readonly SubscriptionPricingCalculator $pricing,
        private readonly BillingPeriodCalculator $periods,
    ) {}

    public function execute(
        AccountSubscription $subscription,
        AccountSubscriptionPaymentType $type,
        ?int $targetLocationCount = null,
        ?Location $pendingLocation = null,
        ?CarbonInterface $chargedAt = null,
        int $renewalAttempt = 0,
    ): AccountSubscriptionPayment {
        $subscription->loadMissing(['account.locations', 'plan', 'priceVersion', 'pendingPriceVersion.plan', 'payments']);

        if (! $subscription->usesLocationBilling() || ! $subscription->plan || ! $subscription->billing_interval_v2) {
            throw new LogicException('The billing-v2 subscription is not ready for payment.');
        }

        $chargedAt ??= now();
        $periodStart = $this->periodStart($subscription, $type, $chargedAt);
        $priceVersion = $type === AccountSubscriptionPaymentType::LocationUpgrade && $subscription->priceVersion
            ? $subscription->priceVersion
            : $this->priceVersionFor($subscription, $chargedAt, $periodStart);
        $priceVersion->loadMissing('plan');
        $quantity = max(1, $targetLocationCount ?? $subscription->account->locations->where('is_active', true)->count());
        $periodEnd = $type === AccountSubscriptionPaymentType::LocationUpgrade
            ? $subscription->ends_at
            : $this->periods->periodEnd($periodStart, $subscription->billing_interval_v2);

        if (! $periodEnd) {
            throw new LogicException('The subscription period is unavailable.');
        }

        $proration = null;
        $previousQuantity = null;

        if ($type === AccountSubscriptionPaymentType::LocationUpgrade) {
            $currentPeriodStart = $subscription->payments()
                ->where('status', AccountSubscriptionPaymentStatus::PaymentPaid->value)
                ->latest('id')
                ->first()?->period_starts_at ?? $subscription->started_at;

            if (! $currentPeriodStart || ! $subscription->ends_at) {
                throw new LogicException('The current paid period is unavailable for proration.');
            }

            $proration = new SubscriptionProrationPeriod($currentPeriodStart, $subscription->ends_at, $chargedAt);
            $previousQuantity = max(1, (int) $subscription->billable_location_count);
        }

        $targetQuote = $this->pricing->calculate(
            $priceVersion,
            $quantity,
            $subscription->billing_interval_v2,
            $proration,
        );
        $previousQuote = $previousQuantity === null
            ? null
            : $this->pricing->calculate(
                $priceVersion,
                $previousQuantity,
                $subscription->billing_interval_v2,
                $proration,
            );
        $amountCents = $targetQuote->finalAmountCents - ($previousQuote?->finalAmountCents ?? 0);

        if ($amountCents <= 0) {
            throw new LogicException('The requested billing change does not increase the price.');
        }

        $idempotencyBase = implode(':', [
            'billing-v2',
            $subscription->id,
            $type->value,
            $this->idempotencyAnchor($subscription, $type, $periodStart)->getTimestamp(),
            $quantity,
            $pendingLocation?->id ?? 0,
        ]);
        $existingPaymentQuery = AccountSubscriptionPayment::query()
            ->where('account_subscription_id', $subscription->id)
            ->where('idempotency_key', 'like', $idempotencyBase.':%')
            ->whereIn('status', [
                AccountSubscriptionPaymentStatus::PaymentStarted->value,
                AccountSubscriptionPaymentStatus::PaymentPending->value,
            ]);

        if ($renewalAttempt > 0) {
            $existingPaymentQuery->where('renewal_attempt', $renewalAttempt);
        }

        $existingPayment = $existingPaymentQuery
            ->latest('id')
            ->first();

        if ($existingPayment) {
            return $existingPayment;
        }

        if ($renewalAttempt < 1) {
            $renewalAttempt = max(1, ((int) AccountSubscriptionPayment::query()
                ->where('account_subscription_id', $subscription->id)
                ->where('idempotency_key', 'like', $idempotencyBase.':%')
                ->max('renewal_attempt')) + 1);
        }

        $idempotencyKey = $idempotencyBase.':'.$renewalAttempt;

        return AccountSubscriptionPayment::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'account_id' => $subscription->account_id,
                'pending_location_id' => $pendingLocation?->id,
                'account_subscription_id' => $subscription->id,
                'subscription_plan_id' => $priceVersion->subscription_plan_id,
                'subscription_price_version_id' => $priceVersion->id,
                'plan_name_snapshot' => $priceVersion->plan?->name ?? $subscription->plan->name,
                'provider' => IntegrationProvider::Monopay->value,
                'payment_type' => $type,
                'order_id' => 'SAAS-V2-'.now()->format('YmdHis').'-'.Str::upper(Str::random(12)),
                'amount_cents' => $amountCents,
                'currency' => $targetQuote->currency,
                'billing_interval_snapshot' => $subscription->billing_interval_v2->value,
                'billable_location_count' => $quantity,
                'tier_breakdown_snapshot' => [
                    'tiers' => $targetQuote->toArray()['tier_breakdown'],
                    'previous_quantity' => $previousQuantity,
                    'proration_factor' => $targetQuote->prorationFactor,
                ],
                'subtotal_cents' => $targetQuote->subtotalCents - ($previousQuote?->subtotalCents ?? 0),
                'discount_cents' => $targetQuote->discountCents - ($previousQuote?->discountCents ?? 0),
                'renewal_attempt' => $renewalAttempt,
                'period_starts_at' => $periodStart,
                'period_ends_at' => $periodEnd,
                'started_at' => $chargedAt,
                'expires_at' => $chargedAt->copy()->addHour(),
            ],
        );
    }

    private function idempotencyAnchor(
        AccountSubscription $subscription,
        AccountSubscriptionPaymentType $type,
        CarbonInterface $periodStart,
    ): CarbonInterface {
        if ($type === AccountSubscriptionPaymentType::LocationUpgrade) {
            return $subscription->ends_at ?? $periodStart;
        }

        if ($type === AccountSubscriptionPaymentType::FullSubscription) {
            return $subscription->trial_ends_at
                ?? $subscription->ends_at
                ?? $subscription->billing_anchor_at
                ?? $periodStart;
        }

        return $periodStart;
    }

    private function priceVersionFor(
        AccountSubscription $subscription,
        CarbonInterface $chargedAt,
        CarbonInterface $periodStart,
    ): SubscriptionPriceVersion {
        if (
            $subscription->pendingPriceVersion
            && $subscription->pending_tariff_change_at?->lessThanOrEqualTo($periodStart)
        ) {
            return $subscription->pendingPriceVersion;
        }

        $current = $subscription->priceVersion;
        $candidate = $subscription->plan?->currentPriceVersion($chargedAt);

        if (! $candidate) {
            if ($current) {
                return $current;
            }

            throw new LogicException('No published price version is available.');
        }

        if ($current && $candidate->isNot($current) && $candidate->published_at?->greaterThan($chargedAt->copy()->subDays(30))) {
            return $current;
        }

        return $candidate;
    }

    private function periodStart(
        AccountSubscription $subscription,
        AccountSubscriptionPaymentType $type,
        CarbonInterface $chargedAt,
    ): CarbonInterface {
        if ($type === AccountSubscriptionPaymentType::LocationUpgrade) {
            return $chargedAt;
        }

        if (in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true) && $subscription->ends_at) {
            return $subscription->ends_at;
        }

        if ($subscription->status === SubscriptionStatus::Trialing && $subscription->ends_at?->isFuture()) {
            return $subscription->ends_at;
        }

        return $chargedAt;
    }
}
