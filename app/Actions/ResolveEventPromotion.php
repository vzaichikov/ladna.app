<?php

namespace App\Actions;

use App\Enums\PromoCodeDiscountType;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventPromoCode;
use App\Support\Events\EventPromotionQuote;
use App\Support\Promotions\PromotionCodeNormalizer;
use App\Support\Promotions\PromotionDiscountCalculator;
use App\Support\Promotions\PromotionIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ResolveEventPromotion
{
    public function __construct(
        private readonly PromotionCodeNormalizer $codes,
        private readonly PromotionIdentity $identities,
        private readonly PromotionDiscountCalculator $discounts,
    ) {}

    /**
     * @param  array<int|string, int>  $lineSubtotals
     */
    public function execute(
        Event $event,
        array $lineSubtotals,
        ?string $code,
        ?string $email,
        ?string $phone,
        bool $lockForUpdate = false,
    ): EventPromotionQuote {
        $normalizedCode = $this->codes->normalize($code);

        if ($normalizedCode === '') {
            return new EventPromotionQuote(
                promoCode: null,
                pricing: $this->discounts->calculate($lineSubtotals, [], PromoCodeDiscountType::Percent, 0),
                emailHash: null,
                phoneHash: null,
            );
        }

        $promoCode = EventPromoCode::query()
            ->where('account_id', $event->account_id)
            ->where('event_id', $event->id)
            ->where('code', $normalizedCode)
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->first();

        if (! $promoCode
            || ! $promoCode->is_active
            || $promoCode->starts_at->isFuture()
            || $promoCode->ends_at->isPast()
            || strtoupper($promoCode->currency) !== strtoupper($event->currency)) {
            throw $this->invalidCode();
        }

        $eligibleTicketTypeIds = $promoCode->ticketTypes()
            ->whereKey(array_keys($lineSubtotals))
            ->pluck('event_ticket_types.id')
            ->all();
        $pricing = $this->discounts->calculate(
            $lineSubtotals,
            $eligibleTicketTypeIds,
            $promoCode->discount_type,
            $promoCode->discount_value,
        );

        if ($pricing->eligibleSubtotalCents === 0 || $pricing->discountCents === 0) {
            throw $this->invalidCode();
        }

        $event->loadMissing('account');
        $emailHash = $this->identities->emailHash($event->account, $email);
        $phoneHash = $this->identities->phoneHash($event->account, $phone);
        $usage = $this->countedOrders($promoCode);

        if ($promoCode->max_total_uses !== null && (clone $usage)->count() >= $promoCode->max_total_uses) {
            throw $this->usageLimitReached();
        }

        if ($promoCode->max_uses_per_identity !== null && ($emailHash !== null || $phoneHash !== null)) {
            $identityUsage = (clone $usage)
                ->where(function (Builder $query) use ($emailHash, $phoneHash): void {
                    if ($emailHash !== null) {
                        $query->where('promo_email_hash', $emailHash);
                    }

                    if ($phoneHash !== null) {
                        $method = $emailHash === null ? 'where' : 'orWhere';
                        $query->{$method}('promo_phone_hash', $phoneHash);
                    }
                })
                ->count();

            if ($identityUsage >= $promoCode->max_uses_per_identity) {
                throw $this->usageLimitReached();
            }
        }

        return new EventPromotionQuote($promoCode, $pricing, $emailHash, $phoneHash);
    }

    private function countedOrders(EventPromoCode $promoCode): Builder
    {
        return EventOrder::query()
            ->whereBelongsTo($promoCode, 'promoCode')
            ->reservingPromotionUse();
    }

    private function invalidCode(): ValidationException
    {
        return ValidationException::withMessages(['promo_code' => __('app.promo_code_invalid')]);
    }

    private function usageLimitReached(): ValidationException
    {
        return ValidationException::withMessages(['promo_code' => __('app.promo_code_usage_limit_reached')]);
    }
}
