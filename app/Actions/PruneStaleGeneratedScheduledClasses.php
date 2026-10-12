<?php

namespace App\Actions;

use App\Enums\ScheduleKind;
use App\Enums\ScheduleSeriesStatus;
use App\Models\Account;
use App\Models\ScheduledClass;
use App\Models\User;
use App\Support\ScheduleOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PruneStaleGeneratedScheduledClasses
{
    public function __construct(
        private readonly RetireFutureScheduledClass $retireFutureScheduledClass,
        private readonly ScheduleOccupancy $scheduleOccupancy,
    ) {}

    public function execute(Account $account, ?User $actor = null, ?CarbonImmutable $cutoff = null): int
    {
        $cutoff ??= CarbonImmutable::now();

        return DB::transaction(function () use ($account, $actor, $cutoff): int {
            $this->scheduleOccupancy->lockAccount($account);
            $staleClasses = $account->scheduledClasses()
                ->withCount('classBookings')
                ->where('is_generated', true)
                ->where('is_manually_modified', false)
                ->where('starts_at', '>', $cutoff)
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
                if ($this->retireFutureScheduledClass->execute($account, $scheduledClass, $cutoff, $actor)) {
                    $deleted++;
                }
            }

            return $deleted;
        });
    }
}
