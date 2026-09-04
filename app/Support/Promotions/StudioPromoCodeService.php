<?php

namespace App\Support\Promotions;

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
        $normalizedCode = $this->normalizer->normalize($code);
        $promoQuery = $account->studioPromoCodes()
            ->with('classPassPlans:id')
            ->where('code', $normalizedCode);

        if ($lockForUpdate) {
            $promoQuery->lockForUpdate();
        }

        $promoCode = $promoQuery->first();

        if (! $promoCode || ! $promoCode->is_active || now()->lt($promoCode->starts_at) || now()->gt($promoCode->ends_at)) {
            $this->invalid(__('app.promo_code_invalid_or_inactive'));
        }

        if (! $promoCode->classPassPlans->contains('id', $classPassPlan->id)) {
            $this->invalid(__('app.promo_code_not_eligible'));
        }

        if (strtoupper($promoCode->currency) !== strtoupper($classPassPlan->currency)) {
            $this->invalid(__('app.promo_code_currency_mismatch'));
        }

        $emailHash = $this->identity->emailHash($account, $customer->email);
        $phoneHash = $this->identity->phoneHash($account, $customer->phone);
        $usageQuery = CustomerPurchase::query()
            ->whereBelongsTo($promoCode, 'studioPromoCode')
            ->reservingPromotionUse();

        if ($promoCode->max_total_uses !== null && (clone $usageQuery)->count() >= $promoCode->max_total_uses) {
            $this->invalid(__('app.promo_code_total_limit_reached'));
        }

        if ($promoCode->max_uses_per_identity !== null) {
            $identityUses = (clone $usageQuery)
                ->where(function (Builder $query) use ($customer, $emailHash, $phoneHash): void {
                    $query->where('customer_id', $customer->id);

                    if ($emailHash) {
                        $query->orWhere('promo_email_hash', $emailHash);
                    }

                    if ($phoneHash) {
                        $query->orWhere('promo_phone_hash', $phoneHash);
                    }
                })
                ->count();

            if ($identityUses >= $promoCode->max_uses_per_identity) {
                $this->invalid(__('app.promo_code_identity_limit_reached'));
            }
        }

        $quote = $this->calculator->calculate(
            [$classPassPlan->id => $classPassPlan->price_cents],
            [$classPassPlan->id],
            $promoCode->discount_type,
            $promoCode->discount_value,
        );

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

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['promo_code' => $message]);
    }
}
