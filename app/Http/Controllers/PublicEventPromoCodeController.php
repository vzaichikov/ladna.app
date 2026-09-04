<?php

namespace App\Http\Controllers;

use App\Actions\ResolveEventPromotion;
use App\Enums\EventStatus;
use App\Http\Requests\QuoteEventEntrancePromoCodeRequest;
use App\Http\Requests\QuoteEventPromoCodeRequest;
use App\Models\Account;
use App\Models\EventTicketType;
use App\Support\Events\EventPromotionQuote;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PublicEventPromoCodeController extends Controller
{
    public function entrance(
        QuoteEventEntrancePromoCodeRequest $request,
        string $accountSlug,
        string $eventSlug,
        ResolveEventPromotion $promotions,
    ): JsonResponse {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $event = $account->events()
            ->where('slug', $eventSlug)
            ->where('status', EventStatus::Published->value)
            ->where('ends_at', '>', now())
            ->firstOrFail();
        $ticketType = $event->ticketTypes()
            ->whereKey($request->integer('ticket_type_id'))
            ->where('is_active', true)
            ->firstOrFail();

        if (($event->remainingCapacity() !== null && $event->remainingCapacity() < 1)
            || $ticketType->remainingQuantity() < 1) {
            throw ValidationException::withMessages(['ticket_type_id' => __('app.event_not_enough_capacity')]);
        }

        $promotion = $promotions->execute(
            $event,
            [$ticketType->id => $ticketType->price_cents],
            $request->string('promo_code')->toString(),
            $request->string('guest_email')->toString(),
            null,
        );

        return $this->response($event->currency, $promotion);
    }

    public function __invoke(
        QuoteEventPromoCodeRequest $request,
        string $accountSlug,
        string $eventSlug,
        ResolveEventPromotion $promotions,
    ): JsonResponse {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $event = $account->events()
            ->where('slug', $eventSlug)
            ->where('status', EventStatus::Published->value)
            ->where('starts_at', '>', now())
            ->firstOrFail();
        $input = $request->quoteInput();
        $requested = collect($input['items']);
        $ticketTypes = EventTicketType::query()
            ->whereBelongsTo($event)
            ->whereKey($requested->keys())
            ->orderBy('id')
            ->get();

        if ($ticketTypes->count() !== $requested->count()) {
            throw ValidationException::withMessages(['items' => __('app.event_ticket_unavailable')]);
        }

        $remainingCapacity = $event->remainingCapacity();

        if ($remainingCapacity !== null && $requested->sum() > $remainingCapacity) {
            throw ValidationException::withMessages(['items' => __('app.event_not_enough_capacity')]);
        }

        $lineSubtotals = [];

        foreach ($ticketTypes as $ticketType) {
            $quantity = (int) $requested[$ticketType->id];

            if (! $ticketType->salesAreOpen() || $quantity > $ticketType->max_per_order) {
                throw ValidationException::withMessages(['items' => __('app.event_ticket_unavailable')]);
            }

            if ($quantity > $ticketType->remainingQuantity()) {
                throw ValidationException::withMessages(['items' => __('app.event_not_enough_capacity')]);
            }

            $unitPrice = $ticketType->earlyBirdIsAvailableFor($quantity)
                ? $ticketType->early_bird_price_cents
                : $ticketType->price_cents;
            $lineSubtotals[$ticketType->id] = $unitPrice * $quantity;
        }

        $promotion = $promotions->execute(
            $event,
            $lineSubtotals,
            $input['promo_code'],
            $input['buyer_email'] ?? null,
            $input['buyer_phone'] ?? null,
        );

        return $this->response($event->currency, $promotion);
    }

    private function response(string $currency, EventPromotionQuote $promotion): JsonResponse
    {
        return response()->json([
            'subtotal_cents' => $promotion->pricing->subtotalCents,
            'eligible_subtotal_cents' => $promotion->pricing->eligibleSubtotalCents,
            'discount_cents' => $promotion->pricing->discountCents,
            'total_cents' => $promotion->pricing->totalCents,
            'currency' => strtoupper($currency),
            'promo_name' => $promotion->promoCode?->name,
            'promo_code' => $promotion->promoCode?->code,
            'requires_payment' => $promotion->pricing->totalCents > 0,
            'line_discounts' => $promotion->pricing->lineDiscounts,
        ]);
    }
}
