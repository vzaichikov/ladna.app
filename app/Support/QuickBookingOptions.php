<?php

namespace App\Support;

use App\Models\Account;
use Illuminate\Support\Collection;

class QuickBookingOptions
{
    /**
     * @return array{
     *     locations: Collection<int, mixed>,
     *     rooms: Collection<int, mixed>,
     *     trainers: Collection<int, mixed>,
     *     options: Collection<int, array{kind: mixed, definition: array<string, mixed>, classTypes: Collection<int, mixed>}>
     * }
     */
    public function forAccount(Account $account): array
    {
        $locations = $account->locations()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);
        $rooms = $account->rooms()
            ->active()
            ->with('location:id,name')
            ->orderBy('location_id')
            ->orderBy('name')
            ->get(['id', 'location_id', 'name']);
        $trainers = $account->trainers()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);
        $options = collect(ScheduleKindRegistry::all())
            ->filter(fn (array $definition, string $scheduleKind): bool => $account->hasScheduleKindEnabled($scheduleKind))
            ->map(fn (array $definition): array => [
                'kind' => $definition['kind'],
                'definition' => $definition,
                'classTypes' => $account->classTypes()
                    ->active()
                    ->where('schedule_kind', $definition['kind']->value)
                    ->orderBy('name')
                    ->get(['id', 'name', 'default_duration_minutes', 'default_capacity']),
            ])
            ->values();

        return [
            'locations' => $locations,
            'rooms' => $rooms,
            'trainers' => $trainers,
            'options' => $options,
        ];
    }
}
