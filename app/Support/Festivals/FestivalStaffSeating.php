<?php

namespace App\Support\Festivals;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalSeat;
use Illuminate\Database\Eloquent\Collection;

class FestivalStaffSeating
{
    public function __construct(private readonly FestivalSeatReservations $reservations) {}

    /** @return Collection<int, FestivalAdmissionType> */
    public function types(FestivalEdition $edition): Collection
    {
        return $edition->admissionTypes()
            ->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value)
            ->where('is_active', true)
            ->when(! $edition->assigned_seating_enabled, fn ($query) => $query->where('is_assigned_seating', false))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, FestivalAdmissionType>  $types
     * @return array<string, mixed>|null
     */
    public function chart(FestivalEdition $edition, Collection $types): ?array
    {
        $assignedTypes = $types->where('is_assigned_seating', true);
        if (! $edition->assigned_seating_enabled || $assignedTypes->isEmpty()) {
            return null;
        }

        $seats = $edition->seats()
            ->whereIn('festival_admission_type_id', $assignedTypes->modelKeys())
            ->orderBy('id')
            ->get();
        $unavailable = $this->unavailable($edition);
        $unavailableKeys = array_fill_keys($unavailable, true);

        return [
            'floors' => $edition->seating_floors,
            'types' => $assignedTypes->modelKeys(),
            'seats' => $seats->map(fn (FestivalSeat $seat): array => [
                'id' => $seat->id,
                'type' => $seat->festival_admission_type_id,
                'floor' => $seat->floor_key,
                'number' => $seat->seat_number,
                'section' => $seat->section, 'group_kind' => $seat->group_kind, 'group_label' => $seat->group_label,
                'label' => $seat->setRelation('edition', $edition)->label(),
                'accessibility' => $seat->accessibility,
                'sightline' => $seat->sightline,
                'x' => $seat->x,
                'y' => $seat->y,
            ])->all(),
            'unavailable' => $unavailable,
            'available_by_type' => $seats
                ->reject(fn (FestivalSeat $seat): bool => isset($unavailableKeys[$seat->id]))
                ->countBy('festival_admission_type_id')->all(),
        ];
    }

    /** @param array<string, mixed>|null $chart */
    public function remaining(FestivalAdmissionType $type, ?array $chart): int
    {
        $remaining = $type->remainingQuantity();

        return $type->is_assigned_seating ? min($remaining, $chart['available_by_type'][$type->id] ?? 0) : $remaining;
    }

    /** @return list<int> */
    public function unavailable(FestivalEdition $edition): array
    {
        return $edition->seats()
            ->when($edition->assigned_seating_enabled, fn ($query) => $query
                ->where(fn ($seat) => $seat
                    ->where('is_blocked', true)
                    ->orWhereIn('id', $this->reservations->live($edition)->select('id'))
                    ->orWhereDoesntHave('admissionType', fn ($type) => $type
                        ->where('is_active', true)
                        ->where('is_assigned_seating', true))))
            ->pluck('id')
            ->all();
    }
}
