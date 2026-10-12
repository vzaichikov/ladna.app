<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\ScheduleSeries;
use App\Models\User;
use App\Support\ScheduleOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DeleteScheduleSeries
{
    public function __construct(
        private readonly RetireFutureScheduledClass $retireFutureScheduledClass,
        private readonly ScheduleOccupancy $scheduleOccupancy,
    ) {}

    public function execute(Account $account, ScheduleSeries $series, ?User $actor): void
    {
        $cutoff = CarbonImmutable::now();

        DB::transaction(function () use ($account, $series, $actor, $cutoff): void {
            $this->scheduleOccupancy->lockAccount($account);
            $lockedSeries = $account->scheduleSeries()->whereKey($series->id)->lockForUpdate()->firstOrFail();

            foreach ($lockedSeries->scheduledClasses()->where('starts_at', '>', $cutoff)->orderBy('id')->lockForUpdate()->get() as $scheduledClass) {
                $this->retireFutureScheduledClass->execute($account, $scheduledClass, $cutoff, $actor);
            }

            $lockedSeries->delete();
        });
    }
}
