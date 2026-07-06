<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\TelegramAlertType;
use App\Models\Account;

interface TelegramAlertRenderer
{
    public function type(): TelegramAlertType;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(Account $account, array $payload): string;
}
