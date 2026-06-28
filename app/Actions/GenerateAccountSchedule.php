<?php

namespace App\Actions;

use App\Enums\ScheduleSeriesStatus;
use App\Models\Account;
use App\Models\ScheduleSeries;

class GenerateAccountSchedule
{
    public function __construct(
        private readonly GenerateScheduleOccurrences $generateScheduleOccurrences,
        private readonly SyncTrainerSubstitutions $syncTrainerSubstitutions,
    ) {}

    /**
     * @return array{created: int, series: int}
     */
    public function execute(Account $account, ?int $seriesId = null): array
    {
        $totalCreated = 0;
        $totalSeries = 0;

        $account->scheduleSeries()
            ->where('status', ScheduleSeriesStatus::Active->value)
            ->when($seriesId, fn ($query) => $query->whereKey($seriesId))
            ->with(['account', 'location', 'room', 'classType', 'trainer'])
            ->chunkById(100, function ($seriesBatch) use (&$totalCreated, &$totalSeries): void {
                foreach ($seriesBatch as $series) {
                    /** @var ScheduleSeries $series */
                    $totalCreated += $this->generateScheduleOccurrences->execute($series);
                    $totalSeries++;
                }
            });

        $this->syncTrainerSubstitutions->syncGeneratedWindow($account);

        return [
            'created' => $totalCreated,
            'series' => $totalSeries,
        ];
    }
}
