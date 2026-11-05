<?php

namespace App\Support\Festivals;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Models\FestivalEdition;
use App\Models\FestivalSeat;
use App\Models\FestivalTicketOrderSeat;

class FestivalHallOccupancy
{
    /**
     * @param  list<int>  $ownSeatIds
     * @return array<string, mixed>
     */
    public function forOrder(FestivalEdition $edition, array $ownSeatIds): array
    {
        $seats = $edition->seats()->where('account_id', $edition->account_id)->orderBy('id')->get()
            ->map(function (FestivalSeat $seat) use ($edition, $ownSeatIds): array {
                return [
                    'id' => $seat->id, 'floor' => $seat->floor_key, 'section' => $seat->section,
                    'group_kind' => $seat->group_kind, 'group_label' => $seat->group_label,
                    'number' => $seat->seat_number, 'label' => $seat->setRelation('edition', $edition)->label(),
                    'x' => $seat->x, 'y' => $seat->y, 'own' => in_array($seat->id, $ownSeatIds, true),
                ];
            })->all();

        return ['floors' => $edition->seating_floors, 'seats' => $seats, 'updated_at' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function present(FestivalEdition $edition, bool $includeLayout = true): array
    {
        $applicable = $edition->assigned_seating_enabled || $edition->tickets()->whereNotNull('festival_seat_id')->exists();
        $seats = $applicable ? $edition->seats()->where('account_id', $edition->account_id)
            ->with(['admissionType', 'orderSeats' => fn ($links) => $links
                ->whereHas('item.order', fn ($orders) => $orders
                    ->where('account_id', $edition->account_id)->where('festival_edition_id', $edition->id)
                    ->where(fn ($live) => $live->where('status', FestivalTicketOrderStatus::Paid->value)
                        ->orWhere(fn ($pending) => $pending->where('status', FestivalTicketOrderStatus::Pending->value)->where('expires_at', '>', now()))))
                ->with(['item.order', 'item.tickets'])])
            ->orderBy('id')->get()->map(function (FestivalSeat $seat) use ($edition, $includeLayout): array {
                $seat->setRelation('edition', $edition);
                $paid = $seat->orderSeats->first(fn (FestivalTicketOrderSeat $link): bool => $link->item->order->status === FestivalTicketOrderStatus::Paid);
                $ticket = $paid?->item->tickets->firstWhere('festival_seat_id', $seat->id);
                $reservation = $paid ? 'assigned' : (($seat->hold_expires_at?->isFuture() || $seat->orderSeats->isNotEmpty()) ? 'held' : ($seat->is_blocked ? 'blocked' : 'free'));
                $type = $seat->admissionType;
                $result = [
                    'id' => $seat->id, 'floor' => $seat->floor_key, 'section' => $seat->section,
                    'group_kind' => $seat->group_kind, 'group_label' => $seat->group_label,
                    'number' => $seat->seat_number, 'label' => $seat->label(),
                    'type' => $type?->id, 'type_label' => $type?->name,
                    'reservation' => $reservation,
                    'admission' => $paid ? (($ticket?->status !== FestivalTicketStatus::Valid) ? 'invalid' : ($ticket->is_checked_in ? 'checked_in' : 'not_checked_in')) : null,
                    'sellable' => $reservation === 'free' && $edition->assigned_seating_enabled && $type?->is_active && $type?->is_assigned_seating && $type?->delivery_mode === FestivalAdmissionDeliveryMode::Venue,
                    'ticket_id' => $ticket?->id,
                ];
                if ($includeLayout) {
                    $result += ['x' => $seat->x, 'y' => $seat->y, 'accessibility' => $seat->accessibility, 'sightline' => $seat->sightline];
                }

                return $result;
            })->all() : [];
        $counts = array_fill_keys(['free', 'held', 'assigned', 'blocked', 'checked_in', 'valid_assigned'], 0);
        foreach ($seats as $seat) {
            $counts[$seat['reservation']]++;
            $counts['checked_in'] += (int) ($seat['admission'] === 'checked_in');
            $counts['valid_assigned'] += (int) in_array($seat['admission'], ['checked_in', 'not_checked_in'], true);
        }

        return [
            'applicable' => $applicable,
            'assigned_seating_enabled' => $edition->assigned_seating_enabled,
            'floors' => $includeLayout && $applicable ? $edition->seating_floors : null,
            'seats' => $seats, 'counts' => $counts, 'updated_at' => now()->toIso8601String(),
        ];
    }
}
