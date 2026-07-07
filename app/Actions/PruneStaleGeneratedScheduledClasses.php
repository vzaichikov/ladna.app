<?php

namespace App\Actions;

use App\Enums\ScheduleKind;
use App\Enums\ScheduleSeriesStatus;
use App\Models\Account;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PruneStaleGeneratedScheduledClasses
{
    public function execute(Account $account): int
    {
        return DB::transaction(function () use ($account): int {
            $staleClasses = $account->scheduledClasses()
                ->withCount(['classBookings as active_class_bookings_count' => fn ($query) => $query->notCorrectedRemoved()])
                ->where('is_generated', true)
                ->where('is_manually_modified', false)
                ->where('starts_at', '>=', now())
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('class_type_id')
                        ->orWhereDoesntHave('classType')
                        ->orWhereHas('classType', function (Builder $query): void {
                            $query
                                ->where('is_active', false)
                                ->orWhere('schedule_kind', '!=', ScheduleKind::GroupClass->value);
                        })
                        ->orWhereNull('schedule_series_id')
                        ->orWhereDoesntHave('scheduleSeries')
                        ->orWhereHas('scheduleSeries', fn (Builder $query) => $query->where('status', '!=', ScheduleSeriesStatus::Active->value));
                })
                ->lockForUpdate()
                ->get();

            $deleted = 0;

            foreach ($staleClasses as $scheduledClass) {
                /** @var ScheduledClass $scheduledClass */
                if ((int) $scheduledClass->active_class_bookings_count > 0) {
                    continue;
                }

                $scheduledClass->delete();
                $deleted++;
            }

            return $deleted;
        });
    }
}
