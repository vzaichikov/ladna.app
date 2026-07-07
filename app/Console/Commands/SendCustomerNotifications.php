<?php

namespace App\Console\Commands;

use App\Support\CustomerNotifications\CustomerNotificationSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customer-notifications:send {--limit=50 : Maximum pending notifications to process}')]
#[Description('Send due customer notification queue items.')]
class SendCustomerNotifications extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CustomerNotificationSender $sender): int
    {
        $result = $sender->sendPending((int) $this->option('limit'));

        $this->info(__('app.customer_notifications_send_command_result', $result));

        return self::SUCCESS;
    }
}
