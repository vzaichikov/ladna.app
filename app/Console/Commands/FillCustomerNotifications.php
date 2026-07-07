<?php

namespace App\Console\Commands;

use App\Support\CustomerNotifications\CustomerNotificationFiller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customer-notifications:fill {--lookahead-hours=192 : Future class window to scan} {--limit=1000 : Maximum classes and stale queue items to inspect}')]
#[Description('Fill and repair customer notification queue items.')]
class FillCustomerNotifications extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CustomerNotificationFiller $filler): int
    {
        $result = $filler->fill(
            (int) $this->option('lookahead-hours'),
            (int) $this->option('limit'),
        );

        $this->info(__('app.customer_notifications_fill_command_result', $result));

        return self::SUCCESS;
    }
}
