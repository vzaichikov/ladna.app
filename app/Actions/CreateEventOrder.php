<?php

namespace App\Actions;

use App\Enums\EventOrderSource;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventOrderItem;
use App\Models\EventTicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateEventOrder
{
    public function __construct(private readonly IssueEventTickets $issueTickets) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(Event $event, array $input, string $locale): EventOrder
    {
        return DB::transaction(function () use ($event, $input, $locale): EventOrder {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($event->status !== EventStatus::Published || $event->starts_at->isPast()) {
                throw ValidationException::withMessages(['items' => __('app.event_sales_closed')]);
            }

            $requested = collect($input['items'])->keyBy('ticket_type_id');
            $ticketTypes = EventTicketType::query()
                ->whereBelongsTo($event)
                ->whereKey($requested->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($ticketTypes->count() !== $requested->count()) {
                throw ValidationException::withMessages(['items' => __('app.event_ticket_unavailable')]);
            }

            $reservedTotal = (int) EventOrderItem::query()
                ->where('event_id', $event->id)
                ->whereHas('order', fn ($query) => $query
                    ->whereIn('status', [EventOrderStatus::Pending->value, EventOrderStatus::Paid->value, EventOrderStatus::RefundRequired->value])
                    ->where(fn ($query) => $query
                        ->where('status', '!=', EventOrderStatus::Pending->value)
                        ->orWhere('expires_at', '>', now())))
                ->sum('quantity');
            $requestedTotal = (int) $requested->sum('quantity');

            if ($event->capacity !== null && $reservedTotal + $requestedTotal > $event->capacity) {
                throw ValidationException::withMessages(['items' => __('app.event_not_enough_capacity')]);
            }

            $accessToken = Str::random(64);
            $order = EventOrder::query()->create([
                'account_id' => $event->account_id,
                'event_id' => $event->id,
                'source' => EventOrderSource::Checkout,
                'provider' => $input['provider'] ?? null,
                'order_id' => 'EV-'.Str::upper(Str::random(20)),
                'buyer_name' => $input['buyer_name'],
                'buyer_email' => mb_strtolower($input['buyer_email']),
                'buyer_phone' => $input['buyer_phone'] ?? null,
                'locale' => $locale,
                'currency' => $event->currency,
                'access_token_encrypted' => $accessToken,
                'access_token_hash' => hash('sha256', $accessToken),
                'expires_at' => now()->addMinutes(30),
                'terms_accepted_at' => now(),
                'terms_hash' => hash('sha256', 'event-checkout-v1'),
            ]);
            $amount = 0;

            foreach ($ticketTypes as $ticketType) {
                $quantity = (int) $requested[$ticketType->id]['quantity'];

                if (! $ticketType->salesAreOpen() || $quantity > $ticketType->max_per_order) {
                    throw ValidationException::withMessages(['items' => __('app.event_ticket_unavailable')]);
                }

                $reserved = $ticketType->soldOrHeldQuantity();

                if ($reserved + $quantity > $ticketType->inventory) {
                    throw ValidationException::withMessages(['items' => __('app.event_not_enough_capacity')]);
                }

                $earlyAvailable = $ticketType->earlyBirdIsAvailableFor($quantity);
                $unitPrice = $earlyAvailable ? $ticketType->early_bird_price_cents : $ticketType->price_cents;
                $total = $unitPrice * $quantity;
                $amount += $total;
                $order->items()->create([
                    'account_id' => $event->account_id,
                    'event_id' => $event->id,
                    'event_ticket_type_id' => $ticketType->id,
                    'ticket_type_name' => $ticketType->name,
                    'ticket_type_description' => $ticketType->description,
                    'price_tier' => $earlyAvailable ? 'early_bird' : 'regular',
                    'unit_price_cents' => $unitPrice,
                    'quantity' => $quantity,
                    'total_cents' => $total,
                ]);
            }

            if ($amount > 0 && blank($input['provider'] ?? null)) {
                throw ValidationException::withMessages(['provider' => __('app.payment_provider_required')]);
            }

            $order->forceFill(['amount_cents' => $amount])->save();

            if ($amount === 0) {
                $order->forceFill([
                    'status' => EventOrderStatus::Paid,
                    'paid_at' => now(),
                    'expires_at' => null,
                ])->save();
                $this->issueTickets->execute($order);
            }

            return $order->refresh()->load(['event.account', 'items', 'tickets']);
        }, 3);
    }
}
