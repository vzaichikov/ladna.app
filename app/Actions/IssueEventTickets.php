<?php

namespace App\Actions;

use App\Models\EventOrder;
use App\Models\EventTicket;
use Illuminate\Support\Str;

class IssueEventTickets
{
    public function execute(EventOrder $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $existing = $order->tickets()->where('event_order_item_id', $item->id)->count();

            for ($position = $existing; $position < $item->quantity; $position++) {
                $token = Str::random(64);
                EventTicket::query()->create([
                    'account_id' => $order->account_id,
                    'event_id' => $order->event_id,
                    'event_order_id' => $order->id,
                    'event_order_item_id' => $item->id,
                    'event_ticket_type_id' => $item->event_ticket_type_id,
                    'code' => $this->uniqueCode(),
                    'token_encrypted' => $token,
                    'token_hash' => hash('sha256', $token),
                ]);
            }
        }
    }

    private function uniqueCode(): string
    {
        do {
            $value = 'EVT-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4));
        } while (EventTicket::query()->where('code', $value)->exists());

        return $value;
    }
}
