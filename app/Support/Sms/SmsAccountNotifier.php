<?php

namespace App\Support\Sms;

use App\Enums\EmailScenario;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Telegram\Alerts\QueueSmsAccountTelegramAlert;

class SmsAccountNotifier
{
    public function __construct(
        private readonly TransactionalMailDispatcher $mail,
        private readonly QueueSmsAccountTelegramAlert $telegramAlerts,
        private readonly SmsAccountStatus $status,
    ) {}

    public function notifyCurrentIssue(Account $account): void
    {
        $issue = $this->status->forAccount($account)['issue'];

        match ($issue) {
            'outstanding' => $this->outstandingCredit($account),
            'auto_top_up_suspended' => $this->automaticTopUpFailed($account),
            'monthly_cap' => $this->automaticTopUpFailed($account, 'monthly_cap_exceeded'),
            'low_credit' => $this->lowCredit($account),
            default => null,
        };
    }

    public function lowCredit(Account $account): void
    {
        $this->notifyOnce(
            $account,
            'last_low_balance_warning_at',
            EmailScenario::SmsCreditLow,
        );
    }

    public function automaticTopUpFailed(Account $account, ?string $reason = null): void
    {
        $this->notifyOnce(
            $account,
            'last_auto_top_up_failure_warning_at',
            EmailScenario::SmsAutoTopUpFailed,
            $reason,
        );
    }

    public function outstandingCredit(Account $account): void
    {
        $this->notifyOnce(
            $account,
            'last_outstanding_warning_at',
            EmailScenario::SmsOutstandingCredit,
        );
    }

    private function notifyOnce(
        Account $account,
        string $timestampField,
        EmailScenario $scenario,
        ?string $reason = null,
    ): void {
        $wallet = $account->smsWallet()->first();

        if (! $wallet) {
            return;
        }

        $updated = AccountSmsWallet::query()
            ->whereKey($wallet->id)
            ->whereNull($timestampField)
            ->update([$timestampField => now()]);

        if ($updated === 1) {
            $this->mail->smsAccountNotice($account, $scenario, $reason);
        }

        $this->telegramAlerts->execute($account);
    }
}
