<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalTicketOrderStatus;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use App\Models\IntegrationSetting;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateFestivalTicketOrder
{
    public function __construct(private readonly PaymentGatewayRegistry $gateways) {}

    /** @param array<string, mixed> $input */
    public function execute(FestivalEdition $edition, array $input, ?FestivalPortalUser $portalUser = null): FestivalTicketOrder
    {
        if ($portalUser && $portalUser->account_id !== $edition->account_id) {
            abort(404);
        }

        $setting = $this->gateways->availableSettingsFor($edition->account)->first(fn (IntegrationSetting $candidate): bool => $candidate->provider->value === $input['provider']);
        if (! $setting) {
            throw new PaymentGatewayException('Festival payment provider is unavailable.');
        }

        return DB::transaction(function () use ($edition, $input, $portalUser): FestivalTicketOrder {
            $purchase = FestivalEditionPurchase::query()->with('package')->where('festival_edition_id', $edition->id)->lockForUpdate()->first();
            abort_if($purchase?->status === FestivalEditionPurchaseStatus::PaymentReversed, 423, __('app.festival_payment_reversed_readonly'));
            $requested = collect($input['items'])->keyBy('admission_type_id');
            $types = FestivalAdmissionType::query()
                ->where('festival_edition_id', $edition->id)
                ->where('account_id', $edition->account_id)
                ->whereKey($requested->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($types->count() !== $requested->count()) {
                throw ValidationException::withMessages(['items' => __('app.festival_admission_invalid')]);
            }

            $requestedQuantity = (int) $requested->sum(fn (array $item): int => (int) $item['quantity']);
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

            $accessToken = Str::random(64);
            $order = FestivalTicketOrder::query()->create([
                'account_id' => $edition->account_id,
                'festival_edition_id' => $edition->id,
                'festival_portal_user_id' => $portalUser?->id,
                'provider' => $input['provider'],
                'order_id' => 'FTO-'.Str::upper(Str::random(18)),
                'buyer_name' => $input['buyer_name'],
                'buyer_email' => Str::lower(trim($input['buyer_email'])),
                'buyer_phone' => $input['buyer_phone'] ?? null,
                'locale' => $portalUser?->locale ?? app()->getLocale(),
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

            return $order->load('items');
        }, 3);
    }
}
