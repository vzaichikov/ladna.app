<?php

namespace App\Support\Festivals;

use App\Enums\FestivalTicketOrderStatus;
use App\Enums\PromoCodeDiscountType;
use App\Models\FestivalEdition;
use App\Models\FestivalPromoCode;
use App\Models\FestivalTicketOrder;
use App\Support\Promotions\PromotionCodeNormalizer;
use App\Support\Promotions\PromotionDiscountCalculator;
use App\Support\Promotions\PromotionIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class FestivalPromoCodePricing
{
    public function __construct(
        private readonly PromotionCodeNormalizer $codes,
        private readonly PromotionDiscountCalculator $discounts,
        private readonly PromotionIdentity $identities,
    ) {}

    /**
     * @param  array<int|string, int>  $lineSubtotals
     * @param  array<int>  $admissionTypeIds
     * @param  array<int>  $portalUserIds
     */
    public function resolve(
        FestivalEdition $edition,
        ?string $code,
        array $lineSubtotals,
        array $admissionTypeIds,
        ?string $email,
        ?string $phone,
        array $portalUserIds = [],
        bool $lock = false,
    ): FestivalPromotionQuote {
        $normalizedCode = $this->codes->normalize($code);
        $emailHash = $this->identities->emailHash($edition->account, $email);
        $phoneHash = $this->identities->phoneHash($edition->account, $phone);

        if ($normalizedCode === '') {
            return new FestivalPromotionQuote(
                promoCode: null,
                amounts: $this->discounts->calculate($lineSubtotals, [], PromoCodeDiscountType::Fixed, 0),
                emailHash: $emailHash,
                phoneHash: $phoneHash,
            );
        }

        $promoQuery = FestivalPromoCode::query()
            ->where('account_id', $edition->account_id)
            ->where('festival_edition_id', $edition->id)
            ->where('code', $normalizedCode);
        if ($lock) {
            $promoQuery->lockForUpdate();
        }

        $promoCode = $promoQuery->first();
        if (! $promoCode || ! $promoCode->is_active || $promoCode->starts_at->isFuture() || $promoCode->ends_at->isPast()) {
            throw ValidationException::withMessages(['promo_code' => __('app.promo_code_invalid')]);
        }
        if (strtoupper($promoCode->currency) !== strtoupper($edition->account->default_currency)) {
            throw ValidationException::withMessages(['promo_code' => __('app.promo_code_currency_mismatch')]);
        }

        $eligibleIds = $promoCode->admissionTypes()
            ->where('festival_admission_types.account_id', $edition->account_id)
            ->where('festival_admission_types.festival_edition_id', $edition->id)
            ->whereKey($admissionTypeIds)
            ->pluck('festival_admission_types.id')
            ->all();
        $amounts = $this->discounts->calculate(
            $lineSubtotals,
            $eligibleIds,
            $promoCode->discount_type,
            $promoCode->discount_value,
        );

        if ($amounts->eligibleSubtotalCents === 0 || $amounts->discountCents === 0) {
            throw ValidationException::withMessages(['promo_code' => __('app.promo_code_not_applicable')]);
        }

        $usage = $this->usageQuery($promoCode);
        if ($promoCode->total_usage_limit !== null && (clone $usage)->count() >= $promoCode->total_usage_limit) {
            throw ValidationException::withMessages(['promo_code' => __('app.promo_code_usage_limit_reached')]);
        }

        $portalUserIds = collect($portalUserIds)->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        if ($promoCode->per_identity_usage_limit !== null
            && ($portalUserIds !== [] || $emailHash !== null || $phoneHash !== null)) {
            $identityUsage = (clone $usage)
                ->where(function (Builder $query) use ($portalUserIds, $emailHash, $phoneHash): void {
                    $query->whereRaw('1 = 0')
                        ->when($portalUserIds !== [], fn (Builder $query) => $query
                            ->orWhereIn('festival_portal_user_id', $portalUserIds)
                            ->orWhereIn('purchaser_festival_portal_user_id', $portalUserIds))
                        ->when($emailHash !== null, fn (Builder $query) => $query->orWhere('promo_email_hash', $emailHash))
                        ->when($phoneHash !== null, fn (Builder $query) => $query->orWhere('promo_phone_hash', $phoneHash));
                })
                ->count();

            if ($identityUsage >= $promoCode->per_identity_usage_limit) {
                throw ValidationException::withMessages(['promo_code' => __('app.promo_code_identity_limit_reached')]);
            }
        }

        return new FestivalPromotionQuote($promoCode, $amounts, $emailHash, $phoneHash);
    }

    private function usageQuery(FestivalPromoCode $promoCode): Builder
    {
        return FestivalTicketOrder::query()
            ->where('festival_promo_code_id', $promoCode->id)
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', [
                        FestivalTicketOrderStatus::Paid->value,
                        FestivalTicketOrderStatus::PaidRequiresRefund->value,
                        FestivalTicketOrderStatus::Refunded->value,
                    ])
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', FestivalTicketOrderStatus::Pending->value)
                        ->where('expires_at', '>', now()));
            });
    }
}
