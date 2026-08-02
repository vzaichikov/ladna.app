<?php

namespace App\Console\Commands;

use App\Support\Telegram\TelegramUpdateDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram-updates:process {--limit=50 : Maximum due Telegram updates to process}')]
#[Description('Recover and process due Telegram webhook updates.')]
class ProcessTelegramUpdates extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TelegramUpdateDispatcher $dispatcher): int
    {
        $result = $dispatcher->processPending((int) $this->option('limit'));
        $this->info(__('app.telegram_updates_process_command_result', $result));

        return self::SUCCESS;
    }
}
