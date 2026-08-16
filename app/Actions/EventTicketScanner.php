<?php

namespace App\Actions;

use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EventTicketScanner
{
    /** @return array<string, mixed> */
    public function checkIn(Event $event, string $value, User $actor, string $source, ?string $ip, bool $confirmed = false): array
    {
        $ticket = EventTicket::query()
            ->where('account_id', $event->account_id)
            ->where(fn ($query) => $query
                ->where('token_hash', hash('sha256', trim($value)))
                ->orWhere('code', strtoupper(trim($value))))
            ->first();

        if (! $ticket) {
            return ['state' => 'invalid', 'message' => __('app.event_scan_invalid')];
        }

        if ($ticket->event_id !== $event->id) {
            return ['state' => 'wrong_event', 'message' => __('app.event_scan_wrong_event')];
        }

        return DB::transaction(function () use ($ticket, $event, $actor, $source, $ip, $confirmed): array {
            $ticketQuery = EventTicket::query()->with(['order', 'ticketType'])->whereKey($ticket->id);

            if ($confirmed) {
                $ticketQuery->lockForUpdate();
            }

            $ticket = $ticketQuery->firstOrFail();

            if (in_array($event->status, [EventStatus::Cancelled, EventStatus::Archived], true)) {
                return ['state' => 'cancelled_event', 'message' => __('app.event_scan_cancelled')];
            }

            if ($ticket->status !== EventTicketStatus::Valid || $ticket->order->status !== EventOrderStatus::Paid) {
                return ['state' => 'void', 'message' => __('app.event_scan_void')];
            }

            if ($ticket->is_checked_in) {
                $last = $ticket->checkIns()->latest('occurred_at')->first();

                return [
                    'state' => 'already_checked_in',
                    'message' => __('app.event_scan_duplicate'),
                    'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
                    'checked_in_at_label' => $ticket->checked_in_at?->timezone($event->timezone)->format('d.m.Y H:i'),
                    'operator' => $last?->actor_name,
                    'ticket' => $this->ticketSummary($ticket),
                ];
            }

            if (! $confirmed) {
                return [
                    'state' => 'awaiting_confirmation',
                    'message' => __('app.event_scan_ready'),
                    'ticket' => $this->ticketSummary($ticket),
                ];
            }

            $ticket->forceFill(['is_checked_in' => true, 'checked_in_at' => now()])->save();
            $this->audit($ticket, $actor, 'check_in', $source, $ip);

            return ['state' => 'checked_in', 'message' => __('app.event_scan_success'), 'ticket' => $this->ticketSummary($ticket)];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function checkOut(Event $event, EventTicket $ticket, User $actor, string $reason, ?string $ip): array
    {
        abort_unless($ticket->account_id === $event->account_id && $ticket->event_id === $event->id, 404);

        return DB::transaction(function () use ($ticket, $actor, $reason, $ip): array {
            $ticket = EventTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if (! $ticket->is_checked_in) {
                return ['state' => 'not_checked_in', 'message' => __('app.event_scan_not_checked_in')];
            }

            $ticket->forceFill(['is_checked_in' => false, 'checked_in_at' => null])->save();
            $this->audit($ticket, $actor, 'check_out', 'monitor', $ip, $reason);

            return ['state' => 'checked_out', 'message' => __('app.event_scan_checked_out')];
        }, 3);
    }

    private function audit(EventTicket $ticket, User $actor, string $action, string $source, ?string $ip, ?string $reason = null): void
    {
        $ticket->checkIns()->create([
            'account_id' => $ticket->account_id,
            'event_id' => $ticket->event_id,
            'user_id' => $actor->id,
            'action' => $action,
            'source' => $source,
            'request_ip' => $ip,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }

    /** @return array{code: string, type: string|null, customer: string} */
    private function ticketSummary(EventTicket $ticket): array
    {
        return [
            'code' => $ticket->code,
            'type' => $ticket->ticketType?->name,
            'customer' => $ticket->order?->buyer_name ?? __('app.unknown'),
        ];
    }
}
