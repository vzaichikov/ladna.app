<?php

namespace App\Console\Commands;

use App\Models\AccountActivityLog;
use App\Support\AccountActivityLogSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('account-activity-logs:prune')]
#[Description('Delete account activity log entries older than the configured retention period.')]
class PruneAccountActivityLogs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = AccountActivityLogSettings::retentionDays();
        $deleted = AccountActivityLog::query()
            ->where('occurred_at', '<', now()->subDays($retentionDays))
            ->delete();

        $this->info(__('app.account_activity_logs_pruned', ['count' => $deleted]));

        return self::SUCCESS;
    }
}
