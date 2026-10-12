<?php

namespace App\Actions;

use App\Enums\ScheduleSeriesStatus;
use App\Models\Account;
use App\Models\ScheduleSeries;
use App\Models\User;
use App\Support\ScheduleOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GenerateAccountSchedule
{
    public function __construct(
        private readonly GenerateScheduleOccurrences $generateScheduleOccurrences,
        private readonly SyncTrainerSubstitutions $syncTrainerSubstitutions,
        private readonly PruneStaleGeneratedScheduledClasses $pruneStaleGeneratedScheduledClasses,
        private readonly ScheduleOccupancy $scheduleOccupancy,
    ) {}

    /**
     * @return array{created: int, series: int, blocked: int, pruned: int}
     */
    public function execute(Account $account, ?int $seriesId = null, ?User $actor = null, ?CarbonImmutable $cutoff = null): array
    {
        $cutoff ??= CarbonImmutable::now();

        return DB::transaction(function () use ($account, $seriesId, $actor, $cutoff): array {
            $this->scheduleOccupancy->lockAccount($account);
            $totalCreated = 0;
            $totalSeries = 0;
            $totalBlocked = 0;

            $account->scheduleSeries()
                ->when(! $seriesId, fn ($query) => $query->where('status', ScheduleSeriesStatus::Active->value))
                ->when($seriesId, fn ($query) => $query->whereKey($seriesId))
                ->with([
                    'account',
                    'location',
                    'room.activityDirections',
                    'classType.activityDirection',
                    'trainer',
                ])
                ->chunkById(100, function ($seriesBatch) use (&$totalCreated, &$totalSeries, &$totalBlocked, $actor, $cutoff): void {
                    foreach ($seriesBatch as $series) {
                        /** @var ScheduleSeries $series */
                        if ($this->generateScheduleOccurrences->isBlockedByRoomDirection($series)) {
                            $totalBlocked++;
                        }

                        $totalCreated += $this->generateScheduleOccurrences->execute($series, $actor, $cutoff);
                        $totalSeries++;
                    }
                });

            $this->syncTrainerSubstitutions->syncGeneratedWindow($account, $cutoff);

            return [
                'created' => $totalCreated,
                'series' => $totalSeries,
                'blocked' => $totalBlocked,
                'pruned' => $this->pruneStaleGeneratedScheduledClasses->execute($account, $actor, $cutoff),
            ];
        });
    }
}
