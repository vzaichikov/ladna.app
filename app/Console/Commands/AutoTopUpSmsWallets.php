<?php

namespace App\Console\Commands;

use App\Enums\SmsSendingMode;
use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Models\AccountSmsWallet;
use App\Support\Sms\SmsAutoTopUpService;
use App\Support\Sms\SmsServiceSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('sms-wallets:auto-top-up {--limit=100 : Maximum low-balance wallets to process}')]
#[Description('Automatically top up opted-in SMS wallets below their configured threshold')]
class AutoTopUpSmsWallets extends Command
{
    public function handle(SmsAutoTopUpService $autoTopUp, SmsServiceSettings $settings): int
    {
        if (! $settings->enabled()) {
            $this->info(__('app.sms_auto_top_up_command_disabled'));

            return self::SUCCESS;
        }

        $limit = max(1, min(1_000, (int) $this->option('limit')));
        $wallets = AccountSmsWallet::query()
            ->where('auto_top_up_enabled', true)
            ->whereNull('auto_top_up_suspended_at')
            ->whereNotNull('auto_top_up_threshold_cents')
            ->whereNotNull('auto_top_up_target_cents')
            ->whereNotNull('auto_top_up_monthly_cap_cents')
            ->whereRaw('GREATEST(balance_cents - reserved_cents, 0) < auto_top_up_threshold_cents')
            ->whereDoesntHave('topUpPayments', fn (Builder $query): Builder => $query
                ->where('kind', SmsTopUpKind::Automatic->value)
                ->whereIn('status', [
                    SmsTopUpPaymentStatus::PaymentStarted->value,
                    SmsTopUpPaymentStatus::PaymentPending->value,
                ]))
            ->whereHas('account', fn (Builder $query): Builder => $query
                ->operational()
                ->whereHas('customerAuthSetting', fn (Builder $query): Builder => $query
                    ->where('sms_sending_mode', SmsSendingMode::LadnaService->value))
                ->whereHas('subscription.plan', fn (Builder $query): Builder => $query
                    ->where('sms_segment_price_cents', '>', 0)))
            ->with('account')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $payments = 0;
        $failures = 0;

        foreach ($wallets as $wallet) {
            try {
                if ($autoTopUp->attempt($wallet->account)) {
                    $payments++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failures++;
            }
        }

        $this->info(__('app.sms_auto_top_up_command_result', [
            'wallets' => $wallets->count(),
            'payments' => $payments,
            'failures' => $failures,
        ]));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
