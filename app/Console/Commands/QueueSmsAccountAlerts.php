<?php

namespace App\Console\Commands;

use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Support\Sms\SmsAccountNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('sms-wallets:queue-alerts')]
#[Description('Queue current SMS account issues for connected studio owners')]
class QueueSmsAccountAlerts extends Command
{
    public function handle(SmsAccountNotifier $notifier): int
    {
        Account::query()->operational()->active()
            ->whereHas('smsWallet', fn (Builder $query): Builder => $query->where('telegram_alerts_enabled', true))
            ->whereHas('customerAuthSetting', fn (Builder $query): Builder => $query->where('sms_sending_mode', SmsSendingMode::LadnaService->value))
            ->whereHas('subscription.plan', fn (Builder $query): Builder => $query->where('sms_segment_price_cents', '>', 0))
            ->with(['smsWallet', 'customerAuthSetting', 'subscription.plan'])
            ->eachById(fn (Account $account) => $notifier->notifyCurrentIssue($account), 100);

        return self::SUCCESS;
    }
}
