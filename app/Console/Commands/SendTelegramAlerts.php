<?php

namespace App\Console\Commands;

use App\Support\Telegram\Alerts\TelegramAlertSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram-alerts:send {--limit=50 : Maximum pending alerts to process}')]
#[Description('Send pending Telegram business alerts.')]
class SendTelegramAlerts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TelegramAlertSender $sender): int
    {
        $result = $sender->sendPending((int) $this->option('limit'));

        $this->info(__('app.telegram_alerts_send_command_result', $result));

        return self::SUCCESS;
    }
}
