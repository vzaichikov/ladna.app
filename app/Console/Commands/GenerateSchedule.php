<?php

namespace App\Console\Commands;

use App\Actions\GenerateAccountSchedule;
use App\Actions\GenerateScheduleOccurrences;
use App\Actions\SyncTrainerSubstitutions;
use App\Enums\AccountStatus;
use App\Enums\ScheduleSeriesStatus;
use App\Models\Account;
use App\Models\ScheduleSeries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('schedule:generate {--series= : Generate one schedule series by id} {--account= : Generate active schedule series for one account id}')]
#[Description('Generate rolling scheduled class occurrences from active weekly schedule series.')]
class GenerateSchedule extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        GenerateScheduleOccurrences $generateScheduleOccurrences,
        GenerateAccountSchedule $generateAccountSchedule,
        SyncTrainerSubstitutions $syncTrainerSubstitutions,
    ): int {
        $seriesId = filled($this->option('series')) ? (int) $this->option('series') : null;
        $accountId = filled($this->option('account')) ? (int) $this->option('account') : null;

        if ($accountId) {
            $account = Account::query()
                ->whereKey($accountId)
                ->where('status', AccountStatus::Active->value)
                ->first();

            if (! $account) {
                $this->error('Active account not found.');

                return self::FAILURE;
            }

            $result = $generateAccountSchedule->execute($account, $seriesId);

            $this->info("Generated {$result['created']} scheduled classes from {$result['series']} schedule series.");

            return self::SUCCESS;
        }

        $query = ScheduleSeries::query()
            ->with(['account', 'location', 'room', 'classType', 'trainer'])
            ->where('status', ScheduleSeriesStatus::Active->value)
            ->whereHas('account', fn ($query) => $query->where('status', AccountStatus::Active->value));

        if ($seriesId) {
            $query->whereKey($seriesId);
        }

        $totalCreated = 0;
        $totalSeries = 0;
        $processedAccountIds = collect();

        $query->chunkById(100, function ($seriesBatch) use (&$totalCreated, &$totalSeries, $generateScheduleOccurrences, $processedAccountIds): void {
            foreach ($seriesBatch as $series) {
                $totalCreated += $generateScheduleOccurrences->execute($series);
                $totalSeries++;
                $processedAccountIds->push($series->account_id);
            }
        });

        $this->syncProcessedAccounts($processedAccountIds, $syncTrainerSubstitutions);

        $this->info("Generated {$totalCreated} scheduled classes from {$totalSeries} schedule series.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, int>  $processedAccountIds
     */
    private function syncProcessedAccounts(Collection $processedAccountIds, SyncTrainerSubstitutions $syncTrainerSubstitutions): void
    {
        Account::query()
            ->whereKey($processedAccountIds->unique()->values()->all())
            ->get()
            ->each(function (Account $account) use ($syncTrainerSubstitutions): void {
                $syncTrainerSubstitutions->syncGeneratedWindow($account);
            });
    }
}
