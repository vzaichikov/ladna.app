<?php

namespace App\Support\Festivals;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalTicketOrderStatus;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalSeat;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FestivalSeatReservations
{
    /** @return Builder<FestivalSeat> */
    public function live(FestivalEdition $edition): Builder
    {
        return $this->seats($edition)->where(fn (Builder $seats) => $seats
            ->where('hold_expires_at', '>', now())
            ->orWhereHas('orderSeats.item.order', fn (Builder $orders) => $orders
                ->where('status', FestivalTicketOrderStatus::Paid->value)
                ->orWhere(fn (Builder $pending) => $this->pending($pending))));
    }

    /** @return Builder<FestivalSeat> */
    public function activeHolds(FestivalEdition $edition): Builder
    {
        return $this->seats($edition)->where(fn (Builder $seats) => $seats
            ->where('hold_expires_at', '>', now())
            ->orWhereHas('orderSeats.item.order', fn (Builder $orders) => $this->pending($orders)));
    }

    /** @return ModelCollection<int, FestivalSeat> */
    public function held(FestivalEdition $edition, string $key): ModelCollection
    {
        return $this->seats($edition)->where('hold_key', $key)->where('hold_expires_at', '>', now())->orderBy('id')->get();
    }

    public function preholdCount(FestivalEdition $edition, ?int $typeId = null, ?string $excludingHoldKey = null): int
    {
        return $this->seats($edition)->where('hold_expires_at', '>', now())
            ->when($typeId !== null, fn (Builder $seats) => $seats->where('festival_admission_type_id', $typeId))
            ->when($excludingHoldKey !== null, fn (Builder $seats) => $seats->where('hold_key', '!=', $excludingHoldKey))
            ->count();
    }

    /** @param list<int> $seatIds */
    public function hold(FestivalEdition $edition, string $key, array $seatIds): ?CarbonInterface
    {
        return DB::transaction(function () use ($edition, $key, $seatIds): ?CarbonInterface {
            $edition = FestivalEdition::query()->where('account_id', $edition->account_id)->lockForUpdate()->findOrFail($edition->id);
            $own = $this->seats($edition)->where('hold_key', $key);

            if ($seatIds === []) {
                $own->update(['hold_key' => null, 'hold_expires_at' => null]);

                return null;
            }

            if (! $edition->assigned_seating_enabled
                || ! in_array($edition->status, [FestivalEditionStatus::Published, FestivalEditionStatus::InProgress], true)
                || $edition->cancelled_at !== null || $edition->ends_at?->isPast()
                || $edition->ticketOrders()->where('seat_hold_key', $key)->exists()) {
                $this->unavailable();
            }

            $purchase = $edition->purchase()->with('package')->lockForUpdate()->first();
            abort_if($purchase?->status === FestivalEditionPurchaseStatus::PaymentReversed, 423, __('app.festival_payment_reversed_readonly'));

            $deadline = (clone $own)->where('hold_expires_at', '>', now())->first()?->hold_expires_at
                ?? now()->addMinutes(10)->startOfSecond();

            $selected = $this->lockSeats($edition, $seatIds, $key);
            $types = $edition->admissionTypes()->whereKey($selected->pluck('festival_admission_type_id')->unique()->all())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($selected->groupBy('festival_admission_type_id') as $typeId => $seats) {
                $type = $types->get($typeId);
                if (! $type || ! $type->is_assigned_seating || $type->delivery_mode !== FestivalAdmissionDeliveryMode::Venue
                    || ! $type->saleIsOpen() || $seats->count() > $type->max_per_order
                    || $seats->count() > $type->remainingQuantity($key)) {
                    $this->unavailable();
                }
            }

            if ($purchase) {
                $ordered = FestivalTicketOrderItem::query()->whereHas('order', fn (Builder $orders) => $orders
                    ->where('festival_edition_id', $edition->id)->countsAgainstInventory())->sum('quantity');
                if ($ordered + $this->preholdCount($edition, excludingHoldKey: $key) + $selected->count() > $purchase->package->max_tickets) {
                    $this->unavailable();
                }
            }

            $own->whereNotIn('id', $selected->modelKeys())->update(['hold_key' => null, 'hold_expires_at' => null]);
            $this->seats($edition)->whereKey($selected->modelKeys())->update(['hold_key' => $key, 'hold_expires_at' => $deadline]);

            return $deadline;
        }, 3);
    }

    /**
     * @param  list<int>  $seatIds
     * @return ModelCollection<int, FestivalSeat>
     */
    public function consume(FestivalEdition $edition, string $key, array $seatIds): ModelCollection
    {
        $seats = $this->held($edition, $key);
        if (! $edition->assigned_seating_enabled || $seats->isEmpty()
            || $seats->modelKeys() !== collect($seatIds)->map(fn ($id) => (int) $id)->sort()->values()->all()) {
            $this->unavailable();
        }

        return $this->lockSeats($edition, $seatIds, $key);
    }

    /**
     * @param  list<int>  $seatIds
     * @return ModelCollection<int, FestivalSeat>
     */
    public function availableForStaff(FestivalEdition $edition, array $seatIds, FestivalAdmissionType $type): ModelCollection
    {
        if (! $type->is_assigned_seating && $seatIds === []) {
            return new ModelCollection;
        }

        if (! $edition->assigned_seating_enabled || ! $type->is_assigned_seating
            || $type->delivery_mode !== FestivalAdmissionDeliveryMode::Venue || $seatIds === []) {
            $this->unavailable();
        }

        $seats = $this->lockSeats($edition, $seatIds);
        if ($seats->contains(fn (FestivalSeat $seat) => $seat->festival_admission_type_id !== $type->id)) {
            $this->unavailable();
        }

        return $seats;
    }

    /** @param Collection<int, FestivalSeat> $seats */
    public function attach(FestivalTicketOrderItem $item, Collection $seats): void
    {
        foreach ($seats as $seat) {
            $item->orderSeats()->create(['festival_seat_id' => $seat->id]);
            $seat->update(['hold_key' => null, 'hold_expires_at' => null]);
        }
    }

    public function ownsPendingSeats(FestivalTicketOrder $order): bool
    {
        $links = $order->orderSeats()->get();
        if ($links->isEmpty() || $order->status !== FestivalTicketOrderStatus::Pending || ! $order->expires_at?->isFuture()) {
            return false;
        }

        $seatIds = $links->pluck('festival_seat_id');
        $seats = $this->seats($order->edition)->whereKey($seatIds->all())->orderBy('id')->lockForUpdate()->get();
        $expected = $order->items->filter(fn (FestivalTicketOrderItem $item) => $item->admissionType->is_assigned_seating);
        $countsByItem = $links->countBy('festival_ticket_order_item_id');
        if ($seats->count() !== $links->count() || $expected->sum('quantity') !== $links->count()
            || ! $expected->every(fn (FestivalTicketOrderItem $item): bool => $countsByItem->get($item->id) === $item->quantity)) {
            return false;
        }

        $expectedTypes = $expected->pluck('festival_admission_type_id', 'id');

        return $links->every(fn ($link) => $expectedTypes->get($link->festival_ticket_order_item_id) === $seats->find($link->festival_seat_id)?->festival_admission_type_id)
            && ! $this->seats($order->edition)->whereKey($seatIds->all())->where(fn (Builder $query) => $query
                ->where('is_blocked', true)
                ->orWhere('hold_expires_at', '>', now())
                ->orWhereHas('orderSeats.item.order', fn (Builder $other) => $other
                    ->whereKeyNot($order->id)
                    ->where(fn (Builder $active) => $active->where('status', FestivalTicketOrderStatus::Paid->value)
                        ->orWhere(fn (Builder $pending) => $this->pending($pending)))))->exists();
    }

    /**
     * @param  list<array{admission_type_id: int, quantity: int}>  $items
     * @param  Collection<int, FestivalSeat>  $seats
     * @return Collection<int, array{admission_type_id: int, quantity: int}>
     */
    public function checkoutItems(array $items, Collection $seats): Collection
    {
        return collect($items)->keyBy('admission_type_id')->union(
            $seats->countBy('festival_admission_type_id')->map(fn (int $quantity, int $id): array => ['admission_type_id' => $id, 'quantity' => $quantity])
        )->sortKeys();
    }

    /**
     * @param  list<int>  $seatIds
     * @param  array<int, array{quantity: int, price_cents: int, tier: string}>  $prices
     */
    public function quoteFingerprint(int $editionId, string $currency, array $seatIds, array $prices): string
    {
        sort($seatIds, SORT_NUMERIC);
        ksort($prices, SORT_NUMERIC);

        return hash('sha256', json_encode([$editionId, strtoupper($currency), $seatIds, $prices], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<int>  $seatIds
     * @return ModelCollection<int, FestivalSeat>
     */
    private function lockSeats(FestivalEdition $edition, array $seatIds, ?string $ownKey = null): ModelCollection
    {
        $seats = $this->seats($edition)->whereKey($seatIds)->orderBy('id')->lockForUpdate()->get();
        if ($seats->count() !== count($seatIds) || $seats->contains('is_blocked', true)
            || $this->live($edition)->whereKey($seatIds)->when($ownKey !== null, fn (Builder $query) => $query
                ->where(fn (Builder $other) => $other->whereNull('hold_key')->orWhere('hold_key', '!=', $ownKey)))->exists()) {
            $this->unavailable();
        }

        return $seats;
    }

    /** @return Builder<FestivalSeat> */
    private function seats(FestivalEdition $edition): Builder
    {
        return FestivalSeat::query()->where('festival_edition_id', $edition->id)->where('account_id', $edition->account_id);
    }

    /** @param Builder<FestivalTicketOrder> $orders */
    private function pending(Builder $orders): Builder
    {
        return $orders->where('status', FestivalTicketOrderStatus::Pending->value)->where('expires_at', '>', now());
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['seat_ids' => __('app.festival_seating.unavailable')]);
    }
}
