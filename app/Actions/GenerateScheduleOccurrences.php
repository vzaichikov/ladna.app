<?php

namespace App\Actions;

use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleSeriesStatus;
use App\Models\ScheduledClass;
use App\Models\ScheduleSeries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GenerateScheduleOccurrences
{
    public function execute(ScheduleSeries $series): int
    {
        $series->loadMissing(['account', 'location', 'room', 'classType', 'trainer']);

        return DB::transaction(function () use ($series): int {
            $timezone = $series->location->timezone
                ?? $series->account->timezone
                ?? config('app.timezone');

            $from = CarbonImmutable::now($timezone)->startOfDay();
            $until = $from->addWeeks($series->account->scheduleGenerationWeeks())->endOfDay();

            if ($series->end_date && $series->end_date->copy()->timezone($timezone)->endOfDay()->lessThan($until)) {
                $until = CarbonImmutable::parse($series->end_date->toDateString(), $timezone)->endOfDay();
            }

            $futureGeneratedClasses = $series->scheduledClasses()
                ->withCount(['classBookings' => fn ($query) => $query->notCorrectedRemoved()])
                ->where('is_generated', true)
                ->where('starts_at', '>=', $from->timezone(config('app.timezone')))
                ->orderBy('starts_at')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (ScheduledClass $scheduledClass): string => $scheduledClass->starts_at->toDateTimeString());

            if (! $this->isGeneratableSeries($series, $timezone, $until)) {
                $this->deleteStaleGeneratedClasses($futureGeneratedClasses, []);

                $series->forceFill([
                    'generated_until' => null,
                    'generated_at' => now(),
                ])->save();

                return 0;
            }

            $cursor = CarbonImmutable::parse($series->start_date->toDateString(), $timezone)->startOfDay();

            if ($cursor->lessThan($from)) {
                $cursor = $from;
            }

            $cursor = $this->nextIsoWeekday($cursor, (int) $series->weekday);
            $desiredOccurrences = [];

            while ($cursor->lessThanOrEqualTo($until)) {
                $startsAt = CarbonImmutable::parse($cursor->toDateString().' '.$series->start_time, $timezone);
                $attributes = $this->occurrenceAttributes($series, $startsAt);
                $desiredOccurrences[$attributes['starts_at']->toDateTimeString()] = $attributes;
                $cursor = $cursor->addWeek();
            }

            $created = $this->syncDesiredOccurrences($futureGeneratedClasses, $desiredOccurrences);
            $this->deleteStaleGeneratedClasses($futureGeneratedClasses, $desiredOccurrences);

            $series->forceFill([
                'generated_until' => $until->toDateString(),
                'generated_at' => now(),
            ])->save();

            return $created;
        });
    }

    private function nextIsoWeekday(CarbonImmutable $date, int $weekday): CarbonImmutable
    {
        $daysUntilTarget = ($weekday - $date->isoWeekday() + 7) % 7;

        return $date->addDays($daysUntilTarget);
    }

    private function isGeneratableSeries(ScheduleSeries $series, string $timezone, CarbonImmutable $until): bool
    {
        if ($series->status !== ScheduleSeriesStatus::Active) {
            return false;
        }

        if (! $series->classType || ! $series->classType->is_active || $series->classType->schedule_kind !== ScheduleKind::GroupClass) {
            return false;
        }

        return ! $series->start_date->copy()->timezone($timezone)->startOfDay()->greaterThan($until);
    }

    /**
     * @return array<string, mixed>
     */
    private function occurrenceAttributes(ScheduleSeries $series, CarbonImmutable $startsAt): array
    {
        $endsAt = $startsAt->addMinutes($series->effectiveDurationMinutes());

        return [
            'account_id' => $series->account_id,
            'location_id' => $series->location_id,
            'room_id' => $series->room_id,
            'class_type_id' => $series->class_type_id,
            'trainer_id' => $series->trainer_id,
            'schedule_series_id' => $series->id,
            'title' => $series->effectiveTitle(),
            'description' => $series->effectiveDescription(),
            'starts_at' => $startsAt->timezone(config('app.timezone')),
            'ends_at' => $endsAt->timezone(config('app.timezone')),
            'capacity' => $series->effectiveCapacity(),
            'booking_cutoff_minutes' => $series->effectiveBookingCutoffMinutes(),
            'cancellation_cutoff_minutes' => $series->effectiveCancellationCutoffMinutes(),
            'is_generated' => true,
            'is_manually_modified' => false,
            'is_public' => $series->classType->schedule_kind === ScheduleKind::GroupClass,
            'status' => ScheduledClassStatus::Scheduled->value,
            'metadata' => [
                'source' => 'schedule_series',
            ],
        ];
    }

    /**
     * @param  Collection<string, ScheduledClass>  $futureGeneratedClasses
     * @param  array<string, array<string, mixed>>  $desiredOccurrences
     */
    private function syncDesiredOccurrences(Collection $futureGeneratedClasses, array $desiredOccurrences): int
    {
        $created = 0;

        foreach ($desiredOccurrences as $startsAtKey => $attributes) {
            $scheduledClass = $futureGeneratedClasses->get($startsAtKey);

            if ($scheduledClass) {
                if (! $scheduledClass->is_manually_modified) {
                    $scheduledClass->forceFill($attributes);

                    if ($scheduledClass->isDirty()) {
                        $scheduledClass->save();
                    }
                }

                continue;
            }

            ScheduledClass::create($attributes);
            $created++;
        }

        return $created;
    }

    /**
     * @param  Collection<string, ScheduledClass>  $futureGeneratedClasses
     * @param  array<string, array<string, mixed>>  $desiredOccurrences
     */
    private function deleteStaleGeneratedClasses(Collection $futureGeneratedClasses, array $desiredOccurrences): void
    {
        foreach ($futureGeneratedClasses as $startsAtKey => $scheduledClass) {
            if (
                array_key_exists($startsAtKey, $desiredOccurrences)
                || $scheduledClass->is_manually_modified
                || (int) $scheduledClass->class_bookings_count > 0
            ) {
                continue;
            }

            $scheduledClass->delete();
        }
    }
}
