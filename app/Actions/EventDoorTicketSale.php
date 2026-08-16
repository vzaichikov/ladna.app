<?php

namespace App\Actions;

use App\Enums\EventOrderSource;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventCashEntry;
use App\Models\EventOrder;
use App\Models\EventTicketType;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventDoorTicketSale
{
    public const ModeCash = 'cash';

    public const ModeCard = 'card';

    public function __construct(
        private readonly IssueEventTickets $issueTickets,
        private readonly RecordEventCashEntry $cashEntries,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly TransactionalMailDispatcher $mailDispatcher,
        private readonly FiscalReceiptService $fiscalReceipts,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(Account $account, Event $event, ?User $actor, array $input, string $mode, string $locale): EventOrder
    {
        $provider = $mode === self::ModeCash ? 'entrance_cash' : (string) ($input['provider'] ?? '');

        if ($mode === self::ModeCard) {
            $this->assertProvider($account, $provider);
        }

        $order = DB::transaction(function () use ($account, $event, $actor, $input, $mode, $locale, $provider): EventOrder {
            $event = Event::query()->whereBelongsTo($account)->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $this->assertEventOpen($event);

            $reference = $this->reference((string) $input['idempotency_key']);
            $existing = EventOrder::query()
                ->where('account_id', $account->id)
                ->where('event_id', $event->id)
                ->where('order_id', $reference)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertReplayMatches($existing, $input, $provider);

                return $existing->load(['items', 'tickets.ticketType']);
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

            if (($event->capacity !== null && $event->soldOrHeldQuantity() + 1 > $event->capacity)
                || $ticketType->soldOrHeldQuantity() + 1 > $ticketType->inventory) {
                throw ValidationException::withMessages(['ticket_type_id' => __('app.event_not_enough_capacity')]);
            }

            $amount = $ticketType->price_cents;
            $isImmediatelyPaid = $mode === self::ModeCash || $amount === 0;
            $accessToken = Str::random(64);
            $order = EventOrder::query()->create([
                'account_id' => $account->id,
                'event_id' => $event->id,
                'source' => EventOrderSource::Entrance,
                'provider' => $isImmediatelyPaid ? 'entrance_cash' : $provider,
                'order_id' => $reference,
                'status' => $isImmediatelyPaid ? EventOrderStatus::Paid : EventOrderStatus::Pending,
                'buyer_name' => trim((string) $input['guest_name']),
                'buyer_email' => filled($input['guest_email'] ?? null) ? mb_strtolower(trim((string) $input['guest_email'])) : null,
                'buyer_phone' => null,
                'locale' => in_array($locale, ['en', 'uk'], true) ? $locale : 'uk',
                'amount_cents' => $amount,
                'currency' => strtoupper($event->currency),
                'access_token_encrypted' => $accessToken,
                'access_token_hash' => hash('sha256', $accessToken),
                'expires_at' => $isImmediatelyPaid ? null : now()->addMinutes(30),
                'paid_at' => $isImmediatelyPaid ? now() : null,
                'terms_accepted_at' => ($input['terms_accepted'] ?? false) ? now() : null,
                'terms_hash' => ($input['terms_accepted'] ?? false) ? hash('sha256', 'event-entrance-v1') : null,
                'issued_by' => $actor?->id,
            ]);
            $order->items()->create([
                'account_id' => $account->id,
                'event_id' => $event->id,
                'event_ticket_type_id' => $ticketType->id,
                'ticket_type_name' => $ticketType->name,
                'ticket_type_description' => $ticketType->description,
                'price_tier' => 'regular',
                'unit_price_cents' => $amount,
                'quantity' => 1,
                'total_cents' => $amount,
            ]);

            if ($isImmediatelyPaid) {
                $this->issueTickets->execute($order);

                if ($mode === self::ModeCash) {
                    $this->cashEntries->execute(
                        $order,
                        $actor,
                        EventCashEntry::DirectionIn,
                        EventCashEntry::PurposeEntranceTicketSale,
                        __('app.entrance_cash_sale_reason'),
                    );
                }
            }

            return $order->refresh()->load(['event.account', 'items', 'tickets.ticketType']);
        }, 3);

        if ($order->status === EventOrderStatus::Paid && $order->wasRecentlyCreated) {
            if (filled($order->buyer_email)) {
                $this->mailDispatcher->eventTicketsIssued($order);
            }

            $this->fiscalReceipts->fiscalizeEventOrder($order);
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

    private function assertEventOpen(Event $event): void
    {
        if ($event->status !== EventStatus::Published || $event->ends_at->isPast()) {
            throw ValidationException::withMessages(['ticket_type_id' => __('app.event_sales_closed')]);
        }
    }

    private function reference(string $idempotencyKey): string
    {
        return 'EVE-'.Str::upper(str_replace('-', '', $idempotencyKey));
    }

    /** @param array<string, mixed> $input */
    private function assertReplayMatches(EventOrder $order, array $input, string $provider): void
    {
        $item = $order->items()->first();

        if ($order->source !== EventOrderSource::Entrance
            || $order->buyer_name !== trim((string) $input['guest_name'])
            || (int) $item?->event_ticket_type_id !== (int) $input['ticket_type_id']
            || ! in_array($order->provider, [$provider, 'entrance_cash'], true)) {
            throw ValidationException::withMessages(['idempotency_key' => __('app.entrance_request_conflict')]);
        }
    }
}
