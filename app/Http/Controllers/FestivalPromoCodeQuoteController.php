<?php

namespace App\Http\Controllers;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Http\Requests\QuoteFestivalEntrancePromoCodeRequest;
use App\Http\Requests\QuoteFestivalPromoCodeRequest;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalPromoCodePricing;
use App\Support\Festivals\FestivalPromotionQuote;
use App\Support\Festivals\FestivalSeatCheckout;
use App\Support\Festivals\FestivalSeatReservations;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class FestivalPromoCodeQuoteController extends Controller
{
    public function admission(
        QuoteFestivalPromoCodeRequest $request,
        string $accountSlug,
        string $editionSlug,
        FestivalPromoCodePricing $pricing,
        FestivalSeatCheckout $seatCheckout,
        FestivalSeatReservations $reservations,
    ): JsonResponse {
        [$account, $edition] = $this->scope($request->attributes->get('festivalAccount'), $accountSlug, $editionSlug);
        $items = $request->validated('items', []);
        $seatIds = array_map(intval(...), $request->validated('seat_ids') ?? []);
        $generalTypeIds = collect($items)->pluck('admission_type_id');
        $requested = collect($items)->keyBy('admission_type_id');
        if ($seatIds !== []) {
            $draft = $request->validated('seat_checkout_id');
            $key = $seatCheckout->key($request, $edition, $draft);
            if (! $edition->assigned_seating_enabled || $key === null || $draft !== $seatCheckout->draft($request, $edition)) {
                throw ValidationException::withMessages(['seat_ids' => __('app.festival_seating.hold_expired')]);
            }

            $seats = $reservations->held($edition, $key);
            if ($seats->count() !== count($seatIds) || $seats->whereIn('id', $seatIds)->count() !== count($seatIds)) {
                throw ValidationException::withMessages(['seat_ids' => __('app.festival_seating.hold_expired')]);
            }

            $requested = $reservations->checkoutItems($items, $seats);
        }

        $types = FestivalAdmissionType::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->whereKey($requested->keys())
            ->with('onlineStream')
            ->orderBy('id')
            ->get();

        if ($types->count() !== $requested->count()
            || $types->contains(fn (FestivalAdmissionType $type): bool => $type->is_assigned_seating && $generalTypeIds->contains($type->id))) {
            throw ValidationException::withMessages(['items' => __('app.festival_admission_invalid')]);
        }

        $requestedQuantity = (int) $requested->sum(fn (array $item): int => (int) $item['quantity']);
        $onlineTypes = $types->where('delivery_mode', FestivalAdmissionDeliveryMode::OnlineStream);
        if ($onlineTypes->isNotEmpty()) {
            if ($seatIds !== [] || $types->count() !== 1 || $requestedQuantity !== 1) {
                throw ValidationException::withMessages(['items' => __('app.festival_online_ticket_separate_order')]);
            }

            $onlineType = $onlineTypes->firstOrFail();
            if (! $onlineType->onlineStream
                || $onlineType->onlineStream->account_id !== $edition->account_id
                || $onlineType->onlineStream->festival_edition_id !== $edition->id) {
                throw ValidationException::withMessages(['items' => __('app.festival_admission_unavailable')]);
            }
            if (! $onlineType->onlineStream->is_enabled) {
                throw ValidationException::withMessages(['items' => __('app.festival_stream_disabled')]);
            }
        }

        $lineSubtotals = [];
        $unitPrices = [];
        $quotePrices = [];
        foreach ($types as $type) {
            $quantity = (int) $requested[$type->id]['quantity'];
            if (! $type->saleIsOpen()
                || $quantity > $type->max_per_order
                || (! $type->is_assigned_seating && $quantity > $type->remainingQuantity())) {
                throw ValidationException::withMessages(['items' => __('app.festival_admission_unavailable')]);
            }

            $price = $type->currentPrice($quantity);
            $quotePrices[$type->id] = ['quantity' => $quantity, ...$price];
            $unitPrices[$type->id] = $price['price_cents'];
            $lineSubtotals[$type->id] = $price['price_cents'] * $quantity;
        }

        $quote = $pricing->resolve(
            $edition,
            $request->validated('promo_code'),
            $lineSubtotals,
            $types->modelKeys(),
            $request->validated('buyer_email'),
            $request->validated('buyer_phone'),
        );

        return $this->response($quote, $account->default_currency, $seatIds === [] ? null : $reservations->quoteFingerprint($edition->id, $account->default_currency, $seatIds, $quotePrices), $unitPrices);
    }

    public function entrance(
        QuoteFestivalEntrancePromoCodeRequest $request,
        string $accountSlug,
        string $editionSlug,
        FestivalPromoCodePricing $pricing,
    ): JsonResponse {
        [$account, $edition] = $this->scope($request->attributes->get('festivalAccount'), $accountSlug, $editionSlug);
        $type = FestivalAdmissionType::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->whereKey($request->integer('ticket_type_id'))
            ->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value)
            ->where('is_assigned_seating', false)
            ->where('is_active', true)
            ->first();

        if (! $type || $type->remainingQuantity() < 1) {
            throw ValidationException::withMessages(['ticket_type_id' => __('app.festival_admission_unavailable')]);
        }

        $quote = $pricing->resolve(
            $edition,
            $request->validated('promo_code'),
            [$type->id => $type->price_cents],
            [$type->id],
            $request->validated('guest_email'),
            null,
        );

        return $this->response($quote, $account->default_currency);
    }

    /** @return array{Account, FestivalEdition} */
    private function scope(mixed $attribute, string $accountSlug, string $editionSlug): array
    {
        abort_unless($attribute instanceof Account && $attribute->slug === $accountSlug, 404);
        $edition = FestivalEdition::query()
            ->whereBelongsTo($attribute)
            ->published()->publiclyVisible()
            ->where('slug', $editionSlug)
            ->with('account')
            ->firstOrFail();

        return [$attribute, $edition];
    }

    private function response(FestivalPromotionQuote $quote, string $currency, ?string $seatQuote = null, array $unitPrices = []): JsonResponse
    {
        return response()->json([
            ...($seatQuote === null ? [] : ['seat_quote' => $seatQuote, 'unit_prices_cents' => (object) $unitPrices]),
            'subtotal_cents' => $quote->amounts->subtotalCents,
            'eligible_subtotal_cents' => $quote->amounts->eligibleSubtotalCents,
            'discount_cents' => $quote->amounts->discountCents,
            'total_cents' => $quote->amounts->totalCents,
            'currency' => strtoupper($currency),
            'promo_name' => $quote->promoCode?->name,
            'promo_code' => $quote->promoCode?->code,
            'requires_payment' => $quote->amounts->totalCents > 0,
            'line_discounts' => (object) $quote->amounts->lineDiscounts,
        ]);
    }
}
