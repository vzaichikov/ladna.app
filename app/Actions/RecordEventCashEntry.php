<?php

namespace App\Actions;

use App\Enums\EventOrderSource;
use App\Models\EventCashEntry;
use App\Models\EventOrder;
use App\Models\User;
use App\Support\ActorSnapshot;
use LogicException;

class RecordEventCashEntry
{
    public function __construct(private readonly ActorSnapshot $actorSnapshot) {}

    public function execute(
        EventOrder $order,
        ?User $actor,
        string $direction,
        string $purpose,
        string $reason,
    ): ?EventCashEntry {
        $order->loadMissing(['account', 'event']);

        if ($order->source !== EventOrderSource::Entrance || $order->provider !== 'entrance_cash') {
            throw new LogicException('Only Event entrance cash orders may enter the Event cash ledger.');
        }

        if ($order->amount_cents === 0) {
            return null;
        }

        $sourceKey = 'event-order:'.$order->id.':'.$purpose;
        $attributes = [
            'account_id' => $order->account_id,
            'event_id' => $order->event_id,
            'event_order_id' => $order->id,
            'source_key' => $sourceKey,
            'direction' => $direction,
            'purpose' => $purpose,
            'amount_cents' => $order->amount_cents,
            'currency' => strtoupper($order->currency),
            'reason' => $reason,
            'occurred_at' => now(),
            ...collect($this->actorSnapshot->capture($order->account, $actor))
                ->except('actor_trainer_id')
                ->all(),
        ];

        $existing = EventCashEntry::query()->where('source_key', $sourceKey)->lockForUpdate()->first();

        if ($existing) {
            $this->assertSameEntry($existing, $attributes);

            return $existing;
        }

        return EventCashEntry::query()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    private function assertSameEntry(EventCashEntry $entry, array $attributes): void
    {
        foreach (['account_id', 'event_id', 'event_order_id', 'direction', 'purpose', 'amount_cents', 'currency'] as $key) {
            if ((string) $entry->getAttribute($key) !== (string) $attributes[$key]) {
                throw new LogicException('Event cash ledger idempotency key does not match the existing entry.');
            }
        }
    }
}
