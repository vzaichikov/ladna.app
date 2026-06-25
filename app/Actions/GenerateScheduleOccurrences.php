<?php

namespace App\Actions;

use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleSeriesStatus;
use App\Models\ScheduledClass;
use App\Models\ScheduleSeries;
use Carbon\CarbonImmutable;
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
            $until = $from->addWeeks((int) config('charm.schedule_generation_weeks', 8))->endOfDay();

            if ($series->end_date && $series->end_date->copy()->timezone($timezone)->endOfDay()->lessThan($until)) {
                $until = CarbonImmutable::parse($series->end_date->toDateString(), $timezone)->endOfDay();
            }

            $series->scheduledClasses()
                ->where('is_generated', true)
                ->where('is_manually_modified', false)
                ->where('starts_at', '>=', $from->timezone(config('app.timezone')))
                ->delete();

            if ($series->status !== ScheduleSeriesStatus::Active || $series->start_date->copy()->timezone($timezone)->startOfDay()->greaterThan($until)) {
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
            $created = 0;

            while ($cursor->lessThanOrEqualTo($until)) {
                $startsAt = CarbonImmutable::parse($cursor->toDateString().' '.$series->start_time, $timezone);
                $endsAt = $startsAt->addMinutes($series->effectiveDurationMinutes());

                ScheduledClass::create([
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
                ]);

                $created++;
                $cursor = $cursor->addWeek();
            }

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
}
