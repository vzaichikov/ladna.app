<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalTicketOrderSource;
use App\Enums\FestivalTicketOrderStatus;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCashEntry;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FestivalDoorTicketSale
{
    public const ModeCash = 'cash';

    public const ModeCard = 'card';

    public function __construct(
        private readonly ResolveFestivalEntranceGuest $resolveGuest,
        private readonly FestivalTicketIssuer $issuer,
        private readonly RecordFestivalCashEntry $cashEntries,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly FiscalReceiptService $fiscalReceipts,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(Account $account, FestivalEdition $edition, ?User $actor, array $input, string $mode, string $locale): FestivalTicketOrder
    {
        $provider = $mode === self::ModeCash ? 'entrance_cash' : (string) ($input['provider'] ?? '');

        if ($mode === self::ModeCard) {
            $this->assertProvider($account, $provider);
        }

        $reference = $this->reference((string) $input['idempotency_key']);
        $existing = FestivalTicketOrder::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->where('order_id', $reference)
            ->first();

        if ($existing) {
            $this->assertReplayMatches($existing, $input, $provider);

            return $existing->load(['items', 'tickets.admissionType']);
        }

        $guest = $this->resolveGuest->execute(
            $account,
            trim((string) $input['guest_name']),
            filled($input['guest_email'] ?? null) ? (string) $input['guest_email'] : null,
            $locale,
        );

        $order = DB::transaction(function () use ($account, $edition, $actor, $input, $mode, $locale, $provider, $reference, $guest): FestivalTicketOrder {
            $edition = FestivalEdition::query()->whereBelongsTo($account)->whereKey($edition->id)->lockForUpdate()->firstOrFail();
            $this->assertEditionOpen($edition);

            $existing = FestivalTicketOrder::query()->where('order_id', $reference)->lockForUpdate()->first();
            if ($existing) {
                $this->assertReplayMatches($existing, $input, $provider);

                return $existing->load(['items', 'tickets.admissionType']);
            }

            $purchase = FestivalEditionPurchase::query()
                ->with('package')
                ->where('festival_edition_id', $edition->id)
                ->lockForUpdate()
                ->first();
            abort_if($purchase?->status === FestivalEditionPurchaseStatus::PaymentReversed, 423, __('app.festival_payment_reversed_readonly'));

            $admissionType = FestivalAdmissionType::query()
                ->where('account_id', $account->id)
                ->where('festival_edition_id', $edition->id)
                ->whereKey((int) $input['ticket_type_id'])
                ->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $admissionType) {
                throw ValidationException::withMessages(['ticket_type_id' => __('app.festival_admission_unavailable')]);
            }

            if ($purchase) {
                $held = (int) FestivalTicketOrderItem::query()
                    ->whereHas('order', fn ($query) => $query
                        ->where('festival_edition_id', $edition->id)
                        ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])
                        ->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
                    ->sum('quantity');

                if ($held + 1 > $purchase->package->max_tickets) {
                    throw ValidationException::withMessages(['ticket_type_id' => __('app.festival_ticket_limit_exceeded', ['limit' => $purchase->package->max_tickets])]);
                }
            }

            if ($admissionType->soldOrHeldQuantity() + 1 > $admissionType->inventory) {
                throw ValidationException::withMessages(['ticket_type_id' => __('app.festival_admission_sold_out')]);
            }

            $amount = $admissionType->price_cents;
            $isImmediatelyPaid = $mode === self::ModeCash || $amount === 0;
            $accessToken = Str::random(64);
            $order = FestivalTicketOrder::query()->create([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'festival_portal_user_id' => $guest->id,
                'source' => FestivalTicketOrderSource::Entrance,
                'issued_by_user_id' => $actor?->id,
                'issued_at' => $isImmediatelyPaid ? now() : null,
                'provider' => $isImmediatelyPaid ? 'entrance_cash' : $provider,
                'order_id' => $reference,
                'status' => $isImmediatelyPaid ? FestivalTicketOrderStatus::Paid : FestivalTicketOrderStatus::Pending,
                'buyer_name' => trim((string) $input['guest_name']),
                'buyer_email' => filled($input['guest_email'] ?? null) ? mb_strtolower(trim((string) $input['guest_email'])) : '',
                'buyer_phone' => null,
                'locale' => in_array($locale, ['en', 'uk'], true) ? $locale : 'uk',
                'amount_cents' => $amount,
                'currency' => strtoupper($edition->currency),
                'access_token_encrypted' => $accessToken,
                'access_token_hash' => hash('sha256', $accessToken),
                'expires_at' => $isImmediatelyPaid ? null : now()->addMinutes(30),
                'paid_at' => $isImmediatelyPaid ? now() : null,
                'terms_accepted_at' => ($input['terms_accepted'] ?? false) ? now() : null,
                'terms_hash' => ($input['terms_accepted'] ?? false) ? hash('sha256', 'festival-entrance-v1') : null,
            ]);
            $order->items()->create([
                'account_id' => $account->id,
                'festival_admission_type_id' => $admissionType->id,
                'admission_name' => $admissionType->name,
                'admission_description' => $admissionType->description,
                'price_tier' => 'regular',
                'unit_price_cents' => $amount,
                'quantity' => 1,
                'total_cents' => $amount,
            ]);

            if ($isImmediatelyPaid) {
                $this->issuer->execute($order, [['holder_name' => $order->buyer_name]]);

                if ($mode === self::ModeCash) {
                    $this->cashEntries->execute(
                        $order,
                        $actor,
                        FestivalCashEntry::DirectionIn,
                        FestivalCashEntry::PurposeEntranceTicketSale,
                        __('app.entrance_cash_sale_reason'),
                    );
                }
            }

            return $order->refresh()->load(['edition.account', 'items', 'tickets.admissionType']);
        }, 3);

        if ($order->status === FestivalTicketOrderStatus::Paid && $order->wasRecentlyCreated) {
            $this->fiscalReceipts->fiscalizeFestivalTicketOrder($order);
        }

        return $order;
    }

    private function assertProvider(Account $account, string $provider): void
    {
        $available = $this->gateways->availableSettingsFor($account)
            ->contains(fn (IntegrationSetting $setting): bool => $setting->provider->value === $provider);

        if (! $available) {
            throw ValidationException::withMessages(['provider' => __('app.payment_provider_unavailable')]);
        }
    }

    private function assertEditionOpen(FestivalEdition $edition): void
    {
        if (! in_array($edition->status, [FestivalEditionStatus::Published, FestivalEditionStatus::InProgress], true)
            || $edition->cancelled_at !== null
            || $edition->ends_at->isPast()) {
            throw ValidationException::withMessages(['ticket_type_id' => __('app.festival_admission_unavailable')]);
        }
    }

    private function reference(string $idempotencyKey): string
    {
        return 'FTE-'.Str::upper(str_replace('-', '', $idempotencyKey));
    }

    /** @param array<string, mixed> $input */
    private function assertReplayMatches(FestivalTicketOrder $order, array $input, string $provider): void
    {
        $item = $order->items()->first();

        if ($order->source !== FestivalTicketOrderSource::Entrance
            || $order->buyer_name !== trim((string) $input['guest_name'])
            || (int) $item?->festival_admission_type_id !== (int) $input['ticket_type_id']
            || ! in_array($order->provider, [$provider, 'entrance_cash'], true)) {
            throw ValidationException::withMessages(['idempotency_key' => __('app.entrance_request_conflict')]);
        }
    }
}
