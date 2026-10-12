<?php

namespace App\Actions;

use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleSeriesStatus;
use App\Models\ScheduledClass;
use App\Models\ScheduleSeries;
use App\Models\User;
use App\Support\RoomActivityDirectionEligibility;
use App\Support\ScheduleOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GenerateScheduleOccurrences
{
    public function __construct(
        private readonly ScheduleOccupancy $scheduleOccupancy,
        private readonly RoomActivityDirectionEligibility $roomActivityDirectionEligibility,
        private readonly RetireFutureScheduledClass $retireFutureScheduledClass,
    ) {}

    public function execute(ScheduleSeries $series, ?User $actor = null, ?CarbonImmutable $cutoff = null): int
    {
        $cutoff ??= CarbonImmutable::now();

        $series->loadMissing([
            'account',
            'location',
            'room.activityDirections',
            'classType.activityDirection',
            'trainer',
        ]);

        return DB::transaction(function () use ($series, $actor, $cutoff): int {
            $this->scheduleOccupancy->lockAccount($series->account);
            $series->refresh()->loadMissing(['location', 'room.activityDirections', 'classType.activityDirection', 'trainer']);
            $trainerIds = $series->trainer_id ? [$series->trainer_id] : [];
            $this->scheduleOccupancy->lockResources($series->account, $series->room_id, $trainerIds);

            $timezone = $series->location->timezone
                ?? $series->account->timezone
                ?? config('app.timezone');

            $from = $cutoff->timezone($timezone)->startOfDay();
            $until = $from->addWeeks($series->account->scheduleGenerationWeeks())->endOfDay();

            if ($series->end_date && $series->end_date->copy()->timezone($timezone)->endOfDay()->lessThan($until)) {
                $until = CarbonImmutable::parse($series->end_date->toDateString(), $timezone)->endOfDay();
            }

            if ($this->isBlockedByRoomDirection($series)) {
                $series->forceFill([
                    'generated_at' => now(),
                ])->save();

                return 0;
            }

            $canGenerate = $this->isGeneratableSeries($series, $timezone, $until);
            $futureGeneratedClasses = $series->scheduledClasses()
                ->withCount('classBookings')
                ->when($canGenerate, fn ($query) => $query->where('is_generated', true))
                ->where('starts_at', '>', $cutoff)
                ->orderBy('starts_at')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (ScheduledClass $scheduledClass): string => $scheduledClass->starts_at->toDateTimeString());

            if (! $canGenerate) {
                $this->deleteStaleGeneratedClasses($series, $futureGeneratedClasses, [], $cutoff, $actor, includeManual: true);

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
                if ($startsAt->lessThanOrEqualTo($cutoff)) {
                    $cursor = $cursor->addWeek();

                    continue;
                }

                $attributes = $this->occurrenceAttributes($series, $startsAt);

                if ($this->scheduleOccupancy->hasInternalClassConflict(
                    $series->account,
                    $series->room_id,
                    $trainerIds,
                    $attributes['starts_at'],
                    $attributes['ends_at'],
                )) {
                    $cursor = $cursor->addWeek();

                    continue;
                }

                $desiredOccurrences[$attributes['starts_at']->toDateTimeString()] = $attributes;
                $cursor = $cursor->addWeek();
            }

            $created = $this->syncDesiredOccurrences($futureGeneratedClasses, $desiredOccurrences);
            $this->deleteStaleGeneratedClasses($series, $futureGeneratedClasses, $desiredOccurrences, $cutoff, $actor);

            $series->forceFill([
                'generated_until' => $until->toDateString(),
                'generated_at' => now(),
            ])->save();

            return $created;
        });
    }

    public function isBlockedByRoomDirection(ScheduleSeries $series): bool
    {
        $series->loadMissing([
            'account',
            'room.activityDirections',
            'classType.activityDirection',
        ]);

        if (
            $series->status !== ScheduleSeriesStatus::Active
            || ! $series->room
            || ! $series->classType
            || ! $series->classType->is_active
            || $series->classType->schedule_kind !== ScheduleKind::GroupClass
        ) {
            return false;
        }

        return ! $this->roomActivityDirectionEligibility->roomCanHost(
            $series->account,
            $series->room,
            $series->classType,
        );
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
                if (! $scheduledClass->is_manually_modified && $scheduledClass->status === ScheduledClassStatus::Scheduled) {
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
    private function deleteStaleGeneratedClasses(ScheduleSeries $series, Collection $futureGeneratedClasses, array $desiredOccurrences, CarbonImmutable $cutoff, ?User $actor, bool $includeManual = false): void
    {
        foreach ($futureGeneratedClasses as $startsAtKey => $scheduledClass) {
            if (
                array_key_exists($startsAtKey, $desiredOccurrences)
                || (! $includeManual && $scheduledClass->is_manually_modified)
            ) {
                continue;
            }

            $this->retireFutureScheduledClass->execute($series->account, $scheduledClass, $cutoff, $actor);
        }
    }
}
