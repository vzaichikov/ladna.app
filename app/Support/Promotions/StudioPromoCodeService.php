<?php

namespace App\Support\Promotions;

use App\Enums\PromoCodeDiscountType;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\StudioPromoCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class StudioPromoCodeService
{
    public function __construct(
        private readonly PromotionCodeNormalizer $normalizer,
        private readonly PromotionIdentity $identity,
        private readonly PromotionDiscountCalculator $calculator,
    ) {}

    /**
     * @return array{promoCode: StudioPromoCode, quote: PromotionQuote, emailHash: string|null, phoneHash: string|null}
     */
    public function quote(
        Account $account,
        ClassPassPlan $classPassPlan,
        Customer $customer,
        string $code,
        bool $lockForUpdate = false,
    ): array {
        return $this->quoteCart($account, $customer, [$classPassPlan->id => $classPassPlan], $code, $lockForUpdate);
    }

    /**
     * @param  array<int, ClassPassPlan>  $plans  One plan per pass, keyed by the cart unit index.
     * @return array{promoCode: StudioPromoCode, quote: PromotionQuote, emailHash: string|null, phoneHash: string|null}
     */
    public function quoteCart(
        Account $account,
        Customer $customer,
        array $plans,
        string $code,
        bool $lockForUpdate = false,
    ): array {
        if ($plans === [] || $customer->account_id !== $account->id) {
            $this->invalid(__('app.promo_code_not_eligible'));
        }

        $normalizedCode = $this->normalizer->normalize($code);
        $promoQuery = $account->studioPromoCodes()
            ->where('code', $normalizedCode);

        if ($lockForUpdate) {
            $promoQuery->lockForUpdate();
        }

        $promoCode = $promoQuery->first();

        if (! $promoCode || ! $promoCode->is_active || now()->lt($promoCode->starts_at) || now()->gt($promoCode->ends_at)) {
            $this->invalid(__('app.promo_code_invalid_or_inactive'));
        }

        $eligiblePlans = $promoCode->classPassPlans();
        $eligiblePlanQuery = $eligiblePlans->newPivotQuery();

        if ($lockForUpdate) {
            $eligiblePlanQuery->lockForUpdate();
        }

        $eligiblePlanIds = array_fill_keys($eligiblePlanQuery->pluck($eligiblePlans->getRelatedPivotKeyName())->all(), true);
        $lineSubtotals = [];
        $eligibleLineIdsByPlan = [];

        foreach ($plans as $unitIndex => $plan) {
            if ($plan->account_id !== $account->id) {
                $this->invalid(__('app.promo_code_not_eligible'));
            }

            if (strtoupper($promoCode->currency) !== strtoupper($plan->currency)) {
                $this->invalid(__('app.promo_code_currency_mismatch'));
            }

            $lineSubtotals[$unitIndex] = $plan->price_cents;

            if (! isset($eligiblePlanIds[$plan->id])) {
                continue;
            }

            if ($promoCode->discount_type === PromoCodeDiscountType::BuyXGetY
                && ($plan->is_trial || ! $plan->is_active || ! $account->hasScheduleKindEnabled($plan->schedule_kind))) {
                continue;
            }

            $eligibleLineIdsByPlan[$plan->id][] = $unitIndex;
        }

        $emailHash = $this->identity->emailHash($account, $customer->email);
        $phoneHash = $this->identity->phoneHash($account, $customer->phone);
        $usageQuery = CustomerPurchase::query()
            ->whereBelongsTo($promoCode, 'studioPromoCode')
            ->reservingPromotionUse();

        if ($promoCode->max_total_uses !== null && $this->usageLimitReached(clone $usageQuery, $promoCode->max_total_uses, $lockForUpdate)) {
            $this->invalid(__('app.promo_code_total_limit_reached'));
        }

        if ($promoCode->max_uses_per_identity !== null) {
            $identityUsageQuery = (clone $usageQuery)
                ->where(function (Builder $query) use ($customer, $emailHash, $phoneHash): void {
                    $query->where('customer_id', $customer->id);

                    if ($emailHash) {
                        $query->orWhere('promo_email_hash', $emailHash);
                    }

                    if ($phoneHash) {
                        $query->orWhere('promo_phone_hash', $phoneHash);
                    }
                });

            if ($this->usageLimitReached($identityUsageQuery, $promoCode->max_uses_per_identity, $lockForUpdate)) {
                $this->invalid(__('app.promo_code_identity_limit_reached'));
            }
        }

        if ($promoCode->discount_type === PromoCodeDiscountType::BuyXGetY) {
            if ($promoCode->buy_quantity < 1 || $promoCode->free_quantity < 1) {
                $this->invalid(__('app.promo_code_not_eligible'));
            }

            $quote = $this->calculator->calculateQuantityDiscount(
                $lineSubtotals,
                $eligibleLineIdsByPlan,
                $promoCode->buy_quantity,
                $promoCode->free_quantity,
            );
        } else {
            $quote = $this->calculator->calculate(
                $lineSubtotals,
                array_merge(...array_values($eligibleLineIdsByPlan)),
                $promoCode->discount_type,
                $promoCode->discount_value,
            );
        }

        if ($quote->eligibleSubtotalCents <= 0 || $quote->discountCents <= 0) {
            $this->invalid(__('app.promo_code_not_eligible'));
        }

        return [
            'promoCode' => $promoCode,
            'quote' => $quote,
            'emailHash' => $emailHash,
            'phoneHash' => $phoneHash,
        ];
    }

    /**
     * Locked checkouts need current reads so transaction snapshots cannot hide another checkout's reserved use.
     */
    private function usageLimitReached(Builder $query, int $limit, bool $lockForUpdate): bool
    {
        if (! $lockForUpdate) {
            return $query->count() >= $limit;
        }

        return $query->limit($limit)->lockForUpdate()->get(['id'])->count() >= $limit;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['promo_code' => $message]);
    }
}
