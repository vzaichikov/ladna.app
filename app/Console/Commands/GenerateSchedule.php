<?php

namespace App\Console\Commands;

use App\Actions\GenerateScheduleOccurrences;
use App\Enums\AccountStatus;
use App\Enums\ScheduleSeriesStatus;
use App\Models\ScheduleSeries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('schedule:generate {--series= : Generate one schedule series by id}')]
#[Description('Generate rolling scheduled class occurrences from active weekly schedule series.')]
class GenerateSchedule extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GenerateScheduleOccurrences $generateScheduleOccurrences): int
    {
        $seriesId = $this->option('series');

        $query = ScheduleSeries::query()
            ->with(['account', 'location', 'room', 'classType', 'instructor'])
            ->where('status', ScheduleSeriesStatus::Active->value)
            ->whereHas('account', fn ($query) => $query->where('status', AccountStatus::Active->value));

        if ($seriesId) {
            $query->whereKey($seriesId);
        }

        $totalCreated = 0;
        $totalSeries = 0;

        $query->chunkById(100, function ($seriesBatch) use (&$totalCreated, &$totalSeries, $generateScheduleOccurrences): void {
            foreach ($seriesBatch as $series) {
                $totalCreated += $generateScheduleOccurrences->execute($series);
                $totalSeries++;
            }
        });

        $this->info("Generated {$totalCreated} scheduled classes from {$totalSeries} schedule series.");

        return self::SUCCESS;
    }
}
