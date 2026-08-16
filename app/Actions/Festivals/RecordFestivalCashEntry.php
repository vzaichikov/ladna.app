<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalTicketOrderSource;
use App\Models\FestivalCashEntry;
use App\Models\FestivalTicketOrder;
use App\Models\User;
use App\Support\ActorSnapshot;
use LogicException;

class RecordFestivalCashEntry
{
    public function __construct(private readonly ActorSnapshot $actorSnapshot) {}

    public function execute(
        FestivalTicketOrder $order,
        ?User $actor,
        string $direction,
        string $purpose,
        string $reason,
    ): ?FestivalCashEntry {
        $order->loadMissing(['account', 'edition']);

        if ($order->source !== FestivalTicketOrderSource::Entrance || $order->provider !== 'entrance_cash') {
            throw new LogicException('Only Festival entrance cash orders may enter the Festival cash ledger.');
        }

        if ($order->amount_cents === 0) {
            return null;
        }

        $sourceKey = 'festival-order:'.$order->id.':'.$purpose;
        $attributes = [
            'account_id' => $order->account_id,
            'festival_edition_id' => $order->festival_edition_id,
            'festival_ticket_order_id' => $order->id,
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

        $existing = FestivalCashEntry::query()->where('source_key', $sourceKey)->lockForUpdate()->first();

        if ($existing) {
            $this->assertSameEntry($existing, $attributes);

            return $existing;
        }

        return FestivalCashEntry::query()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    private function assertSameEntry(FestivalCashEntry $entry, array $attributes): void
    {
        foreach (['account_id', 'festival_edition_id', 'festival_ticket_order_id', 'direction', 'purpose', 'amount_cents', 'currency'] as $key) {
            if ((string) $entry->getAttribute($key) !== (string) $attributes[$key]) {
                throw new LogicException('Festival cash ledger idempotency key does not match the existing entry.');
            }
        }
    }
}
