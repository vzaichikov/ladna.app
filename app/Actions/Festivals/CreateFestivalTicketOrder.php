<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTicketOrderSource;
use App\Enums\FestivalTicketOrderStatus;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use App\Models\IntegrationSetting;
use App\Models\TelegramChatAuthorization;
use App\Support\Festivals\FestivalPromoCodePricing;
use App\Support\Festivals\FestivalSeatReservations;
use App\Support\Festivals\FestivalTelegramIdentityLinker;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateFestivalTicketOrder
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly ResolveFestivalGuest $resolveGuest,
        private readonly FestivalTelegramIdentityLinker $telegramIdentityLinker,
        private readonly FestivalPromoCodePricing $promoCodePricing,
        private readonly FestivalSeatReservations $reservations,
        private readonly FestivalTicketIssuer $issuer,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(
        FestivalEdition $edition,
        array $input,
        ?FestivalPortalUser $portalUser = null,
        ?TelegramChatAuthorization $telegramAuthorization = null,
        ?FestivalPortalUser $purchaser = null,
    ): FestivalTicketOrder {
        if ($portalUser && $portalUser->account_id !== $edition->account_id) {
            abort(404);
        }
        if ($purchaser && ($purchaser->account_id !== $edition->account_id || $purchaser->role !== FestivalPortalRole::Registrant || ! $purchaser->is_active)) {
            abort(404);
        }

        return DB::transaction(function () use ($edition, $input, $portalUser, $telegramAuthorization, $purchaser): FestivalTicketOrder {
            $edition = FestivalEdition::query()
                ->whereKey($edition->id)
                ->where('account_id', $edition->account_id)
                ->lockForUpdate()
                ->firstOrFail();
            $items = $input['items'];
            $seatIds = $input['seat_ids'] ?? [];
            $holdKey = $seatIds === [] ? null : ($input['seat_hold_key'] ?? null);
            if ($seatIds !== [] && (! is_string($holdKey) || ! preg_match('/^[a-f0-9]{64}$/D', $holdKey))) {
                throw ValidationException::withMessages(['seat_ids' => __('app.festival_seating.unavailable')]);
            }

            if ($holdKey !== null && $existing = $edition->ticketOrders()->where('seat_hold_key', $holdKey)->first()) {
                $this->assertReplayMatches($existing, $input);

                return $existing->load('items');
            }

            if (! in_array($edition->status, [FestivalEditionStatus::Published, FestivalEditionStatus::InProgress], true)
                || $edition->ends_at?->isPast() || ($holdKey !== null && $edition->cancelled_at !== null)) {
                throw ValidationException::withMessages(['items' => __('app.festival_admission_unavailable')]);
            }

            $purchase = FestivalEditionPurchase::query()->with('package')->where('festival_edition_id', $edition->id)->lockForUpdate()->first();
            abort_if($purchase?->status === FestivalEditionPurchaseStatus::PaymentReversed, 423, __('app.festival_payment_reversed_readonly'));
            $selectedSeats = $holdKey === null ? new Collection : $this->reservations->consume($edition, $holdKey, $seatIds);
            $seatsByType = $selectedSeats->groupBy('festival_admission_type_id');
            $requested = $this->reservations->checkoutItems($items, $selectedSeats);
            $types = FestivalAdmissionType::query()
                ->where('festival_edition_id', $edition->id)
                ->where('account_id', $edition->account_id)
                ->whereKey($requested->keys())
                ->with('onlineStream')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($requested->isEmpty() || $types->count() !== $requested->count()) {
                throw ValidationException::withMessages(['items' => __('app.festival_admission_invalid')]);
            }

            $quantityTypeIds = collect($items)->pluck('admission_type_id')->map(fn ($id): int => (int) $id);
            if ($types->contains(fn (FestivalAdmissionType $type): bool => $type->is_assigned_seating
                ? $quantityTypeIds->containsStrict($type->id) || ! $seatsByType->has($type->id) || $type->delivery_mode !== FestivalAdmissionDeliveryMode::Venue
                : $seatsByType->has($type->id))) {
                throw ValidationException::withMessages(['seat_ids' => __('app.festival_seating.unavailable')]);
            }

            $requestedQuantity = (int) $requested->sum(fn (array $item): int => (int) $item['quantity']);
            $onlineTypes = $types->where('delivery_mode', FestivalAdmissionDeliveryMode::OnlineStream);
            if ($onlineTypes->isNotEmpty()) {
                if ($types->count() !== 1 || $requestedQuantity !== 1) {
                    throw ValidationException::withMessages(['items' => __('app.festival_online_ticket_separate_order')]);
                }

                $onlineType = $onlineTypes->firstOrFail();
                $stream = $onlineType->onlineStream;
                if (! $stream || $stream->account_id !== $edition->account_id || $stream->festival_edition_id !== $edition->id) {
                    throw ValidationException::withMessages(['items' => __('app.festival_admission_unavailable')]);
                }
                $stream = $stream->newQuery()->whereKey($stream->id)->lockForUpdate()->firstOrFail();
                if (! $stream->is_enabled) {
                    throw ValidationException::withMessages(['items' => __('app.festival_stream_disabled')]);
                }
            }
            if ($purchase) {
                $heldQuantity = (int) FestivalTicketOrderItem::query()
                    ->whereHas('order', fn ($query) => $query
                        ->where('festival_edition_id', $edition->id)
                        ->countsAgainstInventory())
                    ->sum('quantity');
                $preholds = $this->reservations->preholdCount($edition, excludingHoldKey: $holdKey);
                if ($heldQuantity + $preholds + $requestedQuantity > $purchase->package->max_tickets) {
                    throw ValidationException::withMessages(['items' => __('app.festival_ticket_limit_exceeded', ['limit' => $purchase->package->max_tickets])]);
                }
            }

            $prepared = [];
            $quotePrices = [];
            $subtotal = 0;
            foreach ($types as $type) {
                $quantity = (int) $requested[$type->id]['quantity'];
                if (! $type->saleIsOpen() || $quantity > $type->max_per_order) {
                    throw ValidationException::withMessages(['items' => __('app.festival_admission_unavailable')]);
                }
                if ($type->remainingQuantity($holdKey) < $quantity) {
                    throw ValidationException::withMessages(['items' => __('app.festival_admission_sold_out')]);
                }
                $price = $type->currentPrice($quantity);
                $quotePrices[$type->id] = ['quantity' => $quantity, ...$price];
                $total = $price['price_cents'] * $quantity;
                $subtotal += $total;
                $prepared[] = compact('type', 'quantity', 'price', 'total');
            }

            if (! $portalUser) {
                [$firstName, $lastName] = $this->splitName((string) $input['buyer_name']);
                $portalUser = $this->resolveGuest->execute(
                    $edition->account,
                    (string) $input['buyer_email'],
                    $firstName,
                    $lastName,
                    (string) $input['buyer_phone'],
                    (string) ($input['locale'] ?? app()->getLocale()),
                );
            }

            if ($purchaser) {
                $purchaser = FestivalPortalUser::query()
                    ->whereKey($purchaser->id)
                    ->where('account_id', $edition->account_id)
                    ->where('role', FestivalPortalRole::Registrant->value)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            if (! $portalUser) {
                throw ValidationException::withMessages(['buyer_email' => __('app.festival_admission_guest_unavailable')]);
            }
            $portalUser = FestivalPortalUser::query()
                ->whereKey($portalUser->id)
                ->where('account_id', $edition->account_id)
                ->where('role', FestivalPortalRole::Guest->value)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if (! $portalUser) {
                throw ValidationException::withMessages(['buyer_email' => __('app.festival_admission_guest_unavailable')]);
            }

            if ($onlineTypes->isNotEmpty()) {
                $stream = $onlineTypes->firstOrFail()->onlineStream;
                $hasExistingOnlineOrder = FestivalTicketOrder::query()
                    ->where('festival_portal_user_id', $portalUser->id)
                    ->where('festival_edition_id', $edition->id)
                    ->whereHas('items.admissionType', fn ($query) => $query->where('festival_online_stream_id', $stream->id))
                    ->where(fn ($query) => $query
                        ->where(fn ($query) => $query
                            ->where('status', FestivalTicketOrderStatus::Pending->value)
                            ->where('expires_at', '>', now()))
                        ->orWhere(fn ($query) => $query
                            ->where('status', FestivalTicketOrderStatus::Paid->value)
                            ->whereHas('tickets.streamEntitlement', fn ($query) => $query->where('festival_online_stream_id', $stream->id)))
                        ->orWhere('status', FestivalTicketOrderStatus::PaidRequiresRefund->value))
                    ->exists();
                if ($hasExistingOnlineOrder) {
                    throw ValidationException::withMessages(['items' => __('app.festival_online_ticket_already_owned')]);
                }
            }

            $promotion = $this->promoCodePricing->resolve(
                $edition,
                $input['promo_code'] ?? null,
                collect($prepared)->mapWithKeys(fn (array $row): array => [$row['type']->id => $row['total']])->all(),
                $types->modelKeys(),
                (string) $input['buyer_email'],
                (string) $input['buyer_phone'],
                array_filter([$portalUser->id, $purchaser?->id]),
                lock: true,
            );
            $amount = $promotion->amounts->totalCents;

            if ($holdKey !== null
                && (($input['seat_quote'] ?? null) !== $this->reservations->quoteFingerprint($edition->id, $edition->account->default_currency, $selectedSeats->modelKeys(), $quotePrices)
                    || ! isset($input['seat_quote_total']) || (int) $input['seat_quote_total'] !== $amount)) {
                throw ValidationException::withMessages(['seat_quote' => __('app.festival_seating.price_changed')]);
            }

            $provider = filled($input['provider'] ?? null) ? (string) $input['provider'] : null;
            $setting = $provider ? $this->gateways->availableSettingsFor($edition->account)
                ->first(fn (IntegrationSetting $candidate): bool => $candidate->provider->value === $provider) : null;
            if ($amount > 0 && ! $setting) {
                throw ValidationException::withMessages(['provider' => __('app.payment_provider_unavailable')]);
            }

            $accessToken = Str::random(64);
            $order = FestivalTicketOrder::query()->create([
                'account_id' => $edition->account_id,
                'festival_edition_id' => $edition->id,
                'festival_promo_code_id' => $promotion->promoCode?->id,
                'festival_portal_user_id' => $portalUser->id,
                'purchaser_festival_portal_user_id' => $purchaser?->id,
                'source' => FestivalTicketOrderSource::Checkout,
                'provider' => $amount > 0 ? $provider : null,
                'seat_hold_key' => $holdKey,
                'order_id' => 'FTO-'.Str::upper(Str::random(18)),
                'buyer_name' => trim((string) $input['buyer_name']),
                'buyer_email' => Str::lower(trim((string) $input['buyer_email'])),
                'buyer_phone' => (string) $input['buyer_phone'],
                'locale' => in_array(($input['locale'] ?? null), ['en', 'uk'], true) ? $input['locale'] : $edition->account->default_language,
                'promo_name' => $promotion->promoCode?->name,
                'promo_code' => $promotion->promoCode?->code,
                'promo_discount_type' => $promotion->promoCode?->discount_type->value,
                'promo_discount_value' => $promotion->promoCode?->discount_value,
                'subtotal_cents' => $subtotal,
                'discount_cents' => $promotion->amounts->discountCents,
                'promo_email_hash' => $promotion->promoCode ? $promotion->emailHash : null,
                'promo_phone_hash' => $promotion->promoCode ? $promotion->phoneHash : null,
                'amount_cents' => $amount,
                'currency' => strtoupper($edition->account->default_currency),
                'access_token_encrypted' => $accessToken,
                'access_token_hash' => hash('sha256', $accessToken),
                'expires_at' => $selectedSeats->first()?->hold_expires_at ?? now()->addMinutes(30),
                'terms_accepted_at' => now(),
                'terms_hash' => hash('sha256', 'festival-admission-v1'),
            ]);

            foreach ($prepared as $row) {
                $lineDiscount = (int) ($promotion->amounts->lineDiscounts[$row['type']->id] ?? 0);
                $item = $order->items()->create([
                    'account_id' => $edition->account_id,
                    'festival_admission_type_id' => $row['type']->id,
                    'admission_name' => $row['type']->name,
                    'admission_description' => $row['type']->description,
                    'price_tier' => $row['price']['tier'],
                    'unit_price_cents' => $row['price']['price_cents'],
                    'quantity' => $row['quantity'],
                    'total_cents' => $row['total'],
                    'subtotal_cents' => $row['total'],
                    'discount_cents' => $lineDiscount,
                    'final_total_cents' => $row['total'] - $lineDiscount,
                ]);
                if ($seatsByType->has($row['type']->id)) {
                    $this->reservations->attach($item, $seatsByType->get($row['type']->id));
                }
            }

            if ($holdKey !== null && $amount === 0) {
                $order->update(['status' => FestivalTicketOrderStatus::Paid, 'paid_at' => now(), 'expires_at' => null]);
                $this->issuer->execute($order);
            }

            if ($telegramAuthorization) {
                $this->telegramIdentityLinker->linkGuestOrder($telegramAuthorization, $order);
            }

            return $order->load('items');
        }, 3);
    }

    /** @return array{string, string} */
    /** @param array<string, mixed> $input */
    private function assertReplayMatches(FestivalTicketOrder $order, array $input): void
    {
        $originalSeats = $order->orderSeats()->pluck('festival_seat_id')->sort()->values()->all();
        $originalItems = $order->items()->whereDoesntHave('orderSeats')->pluck('quantity', 'festival_admission_type_id')->sortKeys()->all();
        $requestedItems = collect($input['items'])->mapWithKeys(fn (array $item): array => [(int) $item['admission_type_id'] => (int) $item['quantity']])->sortKeys()->all();
        $requestedSeats = collect($input['seat_ids'])->map(fn ($id): int => (int) $id)->sort()->values()->all();

        if ($originalSeats !== $requestedSeats || $originalItems !== $requestedItems
            || $order->buyer_name !== trim((string) $input['buyer_name'])
            || $order->buyer_email !== Str::lower(trim((string) $input['buyer_email']))
            || $order->buyer_phone !== (string) $input['buyer_phone']
            || $order->promo_code !== ($input['promo_code'] ?? null)
            || $order->amount_cents !== (int) ($input['seat_quote_total'] ?? -1)
            || ($order->amount_cents > 0 && $order->provider !== ($input['provider'] ?? null))) {
            throw ValidationException::withMessages(['seat_ids' => __('app.festival_seating.checkout_already_used')]);
        }
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }
}
