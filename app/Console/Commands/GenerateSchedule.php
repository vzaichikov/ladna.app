<?php

namespace App\Console\Commands;

use App\Actions\GenerateAccountSchedule;
use App\Enums\AccountStatus;
use App\Enums\ScheduleSeriesStatus;
use App\Models\Account;
use App\Models\ScheduleSeries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('schedule:generate {--series= : Generate one schedule series by id} {--account= : Generate active schedule series for one account id}')]
#[Description('Generate rolling scheduled class occurrences from active weekly schedule series.')]
class GenerateSchedule extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        GenerateAccountSchedule $generateAccountSchedule,
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

            $this->info($this->summaryLine($result));

            return self::SUCCESS;
        }

        if ($seriesId) {
            $series = ScheduleSeries::query()
                ->with('account')
                ->whereKey($seriesId)
                ->where('status', ScheduleSeriesStatus::Active->value)
                ->whereHas('account', fn ($query) => $query->where('status', AccountStatus::Active->value))
                ->first();

            if (! $series) {
                $this->info($this->summaryLine(['created' => 0, 'series' => 0, 'pruned' => 0]));

                return self::SUCCESS;
            }

            $this->info($this->summaryLine($generateAccountSchedule->execute($series->account, $seriesId)));

            return self::SUCCESS;
        }

        $totalCreated = 0;
        $totalSeries = 0;
        $totalPruned = 0;

        Account::query()
            ->where('status', AccountStatus::Active->value)
            ->chunkById(100, function ($accounts) use (&$totalCreated, &$totalSeries, &$totalPruned, $generateAccountSchedule): void {
                foreach ($accounts as $account) {
                    /** @var Account $account */
                    $result = $generateAccountSchedule->execute($account);
                    $totalCreated += $result['created'];
                    $totalSeries += $result['series'];
                    $totalPruned += $result['pruned'];
                }
            });

        $this->info($this->summaryLine(['created' => $totalCreated, 'series' => $totalSeries, 'pruned' => $totalPruned]));

        return self::SUCCESS;
    }

    /**
     * @param  array{created: int, series: int, pruned: int}  $result
     */
    private function summaryLine(array $result): string
    {
        return "Generated {$result['created']} scheduled classes from {$result['series']} schedule series. Pruned {$result['pruned']} stale generated scheduled classes.";
    }
}
