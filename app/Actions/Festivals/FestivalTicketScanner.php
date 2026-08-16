<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Models\FestivalEdition;
use App\Models\FestivalTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FestivalTicketScanner
{
    /** @return array<string, mixed> */
    public function checkIn(FestivalEdition $edition, string $value, User $actor, string $source, ?string $ip, bool $confirmed = false): array
    {
        $ticket = FestivalTicket::query()
            ->where('account_id', $edition->account_id)
            ->where(fn ($query) => $query->where('token_hash', hash('sha256', trim($value)))->orWhere('code', strtoupper(trim($value))))
            ->first();

        if (! $ticket) {
            return ['state' => 'invalid', 'message' => __('app.festival_scan_invalid')];
        }
        if ($ticket->festival_edition_id !== $edition->id) {
            return ['state' => 'wrong_edition', 'message' => __('app.festival_scan_wrong_edition')];
        }

        return DB::transaction(function () use ($ticket, $edition, $actor, $source, $ip, $confirmed): array {
            $ticketQuery = FestivalTicket::query()->with(['order', 'admissionType'])->whereKey($ticket->id);

            if ($confirmed) {
                $ticketQuery->lockForUpdate();
            }

            $ticket = $ticketQuery->firstOrFail();
            if (in_array($edition->status, [FestivalEditionStatus::Cancelled, FestivalEditionStatus::Archived], true)
                || $ticket->status !== FestivalTicketStatus::Valid
                || $ticket->order->status !== FestivalTicketOrderStatus::Paid) {
                return ['state' => 'void', 'message' => __('app.festival_scan_void')];
            }
            if ($ticket->is_checked_in) {
                return [
                    'state' => 'already_checked_in',
                    'message' => __('app.festival_scan_duplicate'),
                    'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
                    'checked_in_at_label' => $ticket->checked_in_at?->timezone($edition->timezone)->format('d.m.Y H:i'),
                    'ticket' => $this->ticketSummary($ticket),
                ];
            }
            if (! $confirmed) {
                return [
                    'state' => 'awaiting_confirmation',
                    'message' => __('app.festival_scan_ready'),
                    'ticket' => $this->ticketSummary($ticket),
                ];
            }

            $ticket->forceFill(['is_checked_in' => true, 'checked_in_at' => now()])->save();
            $this->audit($ticket, $actor, 'check_in', $source, $ip);

            return ['state' => 'checked_in', 'message' => __('app.festival_scan_success'), 'ticket' => $this->ticketSummary($ticket)];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function checkOut(FestivalEdition $edition, FestivalTicket $ticket, User $actor, string $reason, ?string $ip): array
    {
        abort_unless($ticket->account_id === $edition->account_id && $ticket->festival_edition_id === $edition->id, 404);

        return DB::transaction(function () use ($ticket, $actor, $reason, $ip): array {
            $ticket = FestivalTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (! $ticket->is_checked_in) {
                return ['state' => 'not_checked_in', 'message' => __('app.festival_scan_not_checked_in')];
            }
            $ticket->forceFill(['is_checked_in' => false, 'checked_in_at' => null])->save();
            $this->audit($ticket, $actor, 'check_out', 'monitor', $ip, $reason);

            return ['state' => 'checked_out', 'message' => __('app.festival_scan_checked_out')];
        }, 3);
    }

    private function audit(FestivalTicket $ticket, User $actor, string $action, string $source, ?string $ip, ?string $reason = null): void
    {
        $ticket->scans()->create([
            'account_id' => $ticket->account_id,
            'festival_edition_id' => $ticket->festival_edition_id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'source' => $source,
            'request_ip' => $ip,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }

    /** @return array{code: string, type: string|null, customer: string} */
    private function ticketSummary(FestivalTicket $ticket): array
    {
        return [
            'code' => $ticket->code,
            'type' => $ticket->admissionType?->name,
            'customer' => $ticket->holder_name ?: ($ticket->order?->buyer_name ?? __('app.unknown')),
        ];
    }
}
