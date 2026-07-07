<?php

namespace App\Actions;

use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ScheduledClass;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonImmutable;

class CreateManualScheduledClass
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Account $account, ScheduleKind $scheduleKind, array $validated): ScheduledClass
    {
        $location = $account->locations()->whereKey($validated['location_id'])->firstOrFail();
        $room = $account->rooms()->whereKey($validated['room_id'])->firstOrFail();
        $classType = $account->classTypes()
            ->whereKey($validated['class_type_id'])
            ->where('schedule_kind', $scheduleKind->value)
            ->firstOrFail();
        $trainer = filled($validated['trainer_id'] ?? null)
            ? $account->trainers()->whereKey($validated['trainer_id'])->firstOrFail()
            : null;
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['starts_at'], $timezone);
        $durationMinutes = (int) (($validated['duration_minutes'] ?? null) ?: $classType->default_duration_minutes);
        $endsAt = $startsAt->addMinutes($durationMinutes);

        return $account->scheduledClasses()->create([
            'location_id' => $location->id,
            'room_id' => $room->id,
            'class_type_id' => $classType->id,
            'trainer_id' => $trainer?->id,
            'schedule_series_id' => null,
            'title' => ($validated['title'] ?? null) ?: $classType->name,
            'description' => ($validated['description'] ?? null) ?: $classType->description,
            'starts_at' => $startsAt->timezone(config('app.timezone')),
            'ends_at' => $endsAt->timezone(config('app.timezone')),
            'capacity' => ($validated['capacity'] ?? null) ?? $classType->default_capacity ?? $room->capacity,
            'booking_cutoff_minutes' => ($validated['booking_cutoff_minutes'] ?? null) ?? $classType->booking_cutoff_minutes,
            'cancellation_cutoff_minutes' => ($validated['cancellation_cutoff_minutes'] ?? null) ?? $classType->cancellation_cutoff_minutes,
            'is_generated' => false,
            'is_manually_modified' => false,
            'metadata' => [
                'source' => 'manual',
                'schedule_kind' => $scheduleKind->value,
            ],
            'is_public' => (bool) ScheduleKindRegistry::get($scheduleKind)['default_is_public'],
            'status' => ScheduledClassStatus::Scheduled->value,
        ]);
    }
}
