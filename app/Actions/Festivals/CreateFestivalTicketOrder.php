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
use App\Support\Festivals\FestivalTelegramIdentityLinker;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateFestivalTicketOrder
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly ResolveFestivalGuest $resolveGuest,
        private readonly FestivalTelegramIdentityLinker $telegramIdentityLinker,
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
            if (! in_array($edition->status, [FestivalEditionStatus::Published, FestivalEditionStatus::InProgress], true)
                || $edition->ends_at?->isPast()) {
                throw ValidationException::withMessages(['items' => __('app.festival_admission_unavailable')]);
            }

            $purchase = FestivalEditionPurchase::query()->with('package')->where('festival_edition_id', $edition->id)->lockForUpdate()->first();
            abort_if($purchase?->status === FestivalEditionPurchaseStatus::PaymentReversed, 423, __('app.festival_payment_reversed_readonly'));
            $requested = collect($input['items'])->keyBy('admission_type_id');
            $types = FestivalAdmissionType::query()
                ->where('festival_edition_id', $edition->id)
                ->where('account_id', $edition->account_id)
                ->whereKey($requested->keys())
                ->with('onlineStream')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($types->count() !== $requested->count()) {
                throw ValidationException::withMessages(['items' => __('app.festival_admission_invalid')]);
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
                        ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])
                        ->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
                    ->sum('quantity');
                if ($heldQuantity + $requestedQuantity > $purchase->package->max_tickets) {
                    throw ValidationException::withMessages(['items' => __('app.festival_ticket_limit_exceeded', ['limit' => $purchase->package->max_tickets])]);
                }
            }

            $prepared = [];
            $amount = 0;
            foreach ($types as $type) {
                $quantity = (int) $requested[$type->id]['quantity'];
                if (! $type->saleIsOpen() || $quantity > $type->max_per_order) {
                    throw ValidationException::withMessages(['items' => __('app.festival_admission_unavailable')]);
                }
                if ($type->remainingQuantity() < $quantity) {
                    throw ValidationException::withMessages(['items' => __('app.festival_admission_sold_out')]);
                }
                $price = $type->currentPrice();
                if ($price['tier'] === 'early_bird' && $type->early_bird_quota !== null) {
                    $earlyHeld = (int) $type->orderItems()->where('price_tier', 'early_bird')->whereHas('order', fn ($query) => $query->whereIn('status', ['pending', 'paid'])->where(fn ($query) => $query->where('status', '!=', 'pending')->orWhere('expires_at', '>', now())))->sum('quantity');
                    if ($quantity > $type->early_bird_quota - $earlyHeld) {
                        $price = ['price_cents' => $type->price_cents, 'tier' => 'regular'];
                    }
                }
                $total = $price['price_cents'] * $quantity;
                $amount += $total;
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
                'festival_portal_user_id' => $portalUser->id,
                'purchaser_festival_portal_user_id' => $purchaser?->id,
                'source' => FestivalTicketOrderSource::Checkout,
                'provider' => $amount > 0 ? $provider : null,
                'order_id' => 'FTO-'.Str::upper(Str::random(18)),
                'buyer_name' => trim((string) $input['buyer_name']),
                'buyer_email' => Str::lower(trim((string) $input['buyer_email'])),
                'buyer_phone' => (string) $input['buyer_phone'],
                'locale' => in_array(($input['locale'] ?? null), ['en', 'uk'], true) ? $input['locale'] : $edition->account->default_language,
                'amount_cents' => $amount,
                'currency' => strtoupper($edition->account->default_currency),
                'access_token_encrypted' => $accessToken,
                'access_token_hash' => hash('sha256', $accessToken),
                'expires_at' => now()->addMinutes(30),
                'terms_accepted_at' => now(),
                'terms_hash' => hash('sha256', 'festival-admission-v1'),
            ]);

            foreach ($prepared as $row) {
                $order->items()->create([
                    'account_id' => $edition->account_id,
                    'festival_admission_type_id' => $row['type']->id,
                    'admission_name' => $row['type']->name,
                    'admission_description' => $row['type']->description,
                    'price_tier' => $row['price']['tier'],
                    'unit_price_cents' => $row['price']['price_cents'],
                    'quantity' => $row['quantity'],
                    'total_cents' => $row['total'],
                ]);
            }

            if ($telegramAuthorization) {
                $this->telegramIdentityLinker->linkGuestOrder($telegramAuthorization, $order);
            }

            return $order->load('items');
        }, 3);
    }

    /** @return array{string, string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }
}
