<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\TelegramAlertType;
use App\Models\Account;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\Lang;

class SmsAccountTelegramAlertRenderer implements TelegramAlertRenderer
{
    public function type(): TelegramAlertType
    {
        return TelegramAlertType::SmsAccountIssue;
    }

    /** @param array<string, mixed> $payload */
    public function render(Account $account, array $payload): string
    {
        $locale = array_key_exists($account->default_language, config('ladna.locales', []))
            ? $account->default_language : config('app.locale');

        return implode("\n\n", [
            Lang::get('app.sms_telegram_alert_title', ['studio' => $account->name], $locale),
            Lang::get($payload['warning_key'], $payload['warning_parameters'], $locale),
            Lang::get('app.sms_telegram_alert_balance', [
                'amount' => MoneyFormatter::format($payload['spendable_cents'], 'UAH'),
            ], $locale),
            $payload['action_url'],
        ]);
    }
}
