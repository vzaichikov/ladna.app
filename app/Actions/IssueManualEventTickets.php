<?php

namespace App\Actions;

use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventTicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueManualEventTickets
{
    public const PAYMENT_METHODS = ['cash', 'card', 'bank_transfer', 'other'];

    public function __construct(private readonly IssueEventTickets $issueTickets) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(Account $account, Event $event, User $issuer, array $input, string $locale): EventOrder
    {
        return DB::transaction(function () use ($account, $event, $issuer, $input, $locale): EventOrder {
            $event = Event::query()
                ->whereBelongsTo($account)
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->status !== EventStatus::Published || $event->ends_at->isPast()) {
                throw ValidationException::withMessages(['event' => __('app.event_manual_issue_unavailable')]);
            }

            $ticketType = EventTicketType::query()
                ->whereBelongsTo($account)
                ->whereBelongsTo($event)
                ->whereKey((int) $input['ticket_type_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $ticketType) {
                throw ValidationException::withMessages(['ticket_type_id' => __('app.event_ticket_unavailable')]);
            }

            $quantity = (int) $input['quantity'];

            if ($event->capacity !== null && $event->soldOrHeldQuantity() + $quantity > $event->capacity) {
                throw ValidationException::withMessages(['quantity' => __('app.event_not_enough_capacity')]);
            }

            if ($ticketType->soldOrHeldQuantity() + $quantity > $ticketType->inventory) {
                throw ValidationException::withMessages(['quantity' => __('app.event_not_enough_capacity')]);
            }

            $isPaid = $input['payment_kind'] === 'paid';
            $unitPriceCents = $isPaid ? $ticketType->price_cents : 0;
            $accessToken = Str::random(64);
            $order = EventOrder::query()->create([
                'account_id' => $account->id,
                'event_id' => $event->id,
                'provider' => $isPaid ? 'manual_'.$input['payment_method'] : null,
                'order_id' => 'EV-'.Str::upper(Str::random(20)),
                'status' => EventOrderStatus::Paid,
                'buyer_name' => str((string) $input['buyer_name'])->trim()->toString(),
                'buyer_email' => filled($input['buyer_email'] ?? null)
                    ? str((string) $input['buyer_email'])->trim()->lower()->toString()
                    : null,
                'buyer_phone' => filled($input['buyer_phone'] ?? null)
                    ? str((string) $input['buyer_phone'])->trim()->toString()
                    : null,
                'locale' => in_array($locale, ['en', 'uk'], true) ? $locale : 'uk',
                'amount_cents' => $unitPriceCents * $quantity,
                'currency' => $event->currency,
                'access_token_encrypted' => $accessToken,
                'access_token_hash' => hash('sha256', $accessToken),
                'expires_at' => null,
                'paid_at' => now(),
                'terms_accepted_at' => null,
                'terms_hash' => null,
                'issued_by' => $issuer->id,
            ]);

            $order->items()->create([
                'account_id' => $account->id,
                'event_id' => $event->id,
                'event_ticket_type_id' => $ticketType->id,
                'ticket_type_name' => $ticketType->name,
                'ticket_type_description' => $ticketType->description,
                'price_tier' => $isPaid ? 'regular' : 'complimentary',
                'unit_price_cents' => $unitPriceCents,
                'quantity' => $quantity,
                'total_cents' => $unitPriceCents * $quantity,
            ]);

            $this->issueTickets->execute($order);

            return $order->refresh()->load(['items', 'tickets.ticketType']);
        }, 3);
    }
}
