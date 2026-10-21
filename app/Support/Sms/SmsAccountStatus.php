<?php

namespace App\Support\Sms;

use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Support\MoneyFormatter;

class SmsAccountStatus
{
    public function __construct(
        private readonly AccountSmsPricing $pricing,
        private readonly SmsServiceSettings $settings,
    ) {}

    /**
     * @return array{
     *     mode: SmsSendingMode,
     *     serviceEnabled: bool,
     *     balanceCents: int,
     *     spendableCents: int,
     *     reservedCents: int,
     *     segmentPriceCents: ?int,
     *     approximateSegments: ?int,
     *     issue: ?string,
     *     warningKey: ?string,
     *     warningParameters: array<string, string>
     * }
     */
    public function forAccount(Account $account): array
    {
        $account->loadMissing(['customerAuthSetting', 'subscription.plan', 'smsWallet']);
        $wallet = $account->smsWallet;
        $mode = $account->customerAuthSetting?->sms_sending_mode ?? SmsSendingMode::Disabled;
        $price = $this->pricing->segmentPriceCents($account);
        $available = $wallet?->spendableBalanceCents() ?? 0;
        $serviceEnabled = $this->settings->enabled();
        $paidService = $mode === SmsSendingMode::LadnaService && ($price ?? 0) > 0 && $serviceEnabled;
        $issue = $paidService ? match (true) {
            ($wallet?->outstanding_cents ?? 0) > 0 => 'outstanding',
            $wallet?->auto_top_up_suspended_at !== null => 'auto_top_up_suspended',
            $wallet?->hasInsufficientAutoTopUpMonthlyAllowance($account->timezone ?: config('app.timezone')) ?? false => 'monthly_cap',
            $available < $price => 'low_credit',
            default => null,
        } : null;
        $warningKey = match ($issue) {
            'outstanding' => 'app.sms_outstanding_debt_warning',
            'auto_top_up_suspended' => 'app.sms_auto_top_up_suspended_warning',
            'monthly_cap' => 'app.sms_auto_top_up_monthly_cap_warning',
            'low_credit' => 'app.sms_low_credit_warning',
            default => null,
        };

        return [
            'mode' => $mode,
            'serviceEnabled' => $serviceEnabled,
            'balanceCents' => $wallet?->balance_cents ?? 0,
            'spendableCents' => $available,
            'reservedCents' => $wallet?->reserved_cents ?? 0,
            'segmentPriceCents' => $price,
            'approximateSegments' => ($price ?? 0) > 0 ? intdiv($available, $price) : null,
            'issue' => $issue,
            'warningKey' => $warningKey,
            'warningParameters' => $issue === 'outstanding'
                ? ['amount' => MoneyFormatter::format($wallet->outstanding_cents, 'UAH')]
                : [],
        ];
    }

    public function warningTimestampField(string $issue): string
    {
        return match ($issue) {
            'outstanding' => 'last_outstanding_warning_at',
            'auto_top_up_suspended', 'monthly_cap' => 'last_auto_top_up_failure_warning_at',
            default => 'last_low_balance_warning_at',
        };
    }
}
