<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustSmsWalletRequest;
use App\Models\Account;
use App\Support\Sms\AccountSmsPricing;
use App\Support\Sms\SmsServiceSettings;
use App\Support\Sms\SmsWalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountSmsController extends Controller
{
    public function show(
        Request $request,
        Account $account,
        SmsWalletService $wallets,
        AccountSmsPricing $pricing,
        SmsServiceSettings $settings,
    ): View {
        $activeReportTab = $this->activeReportTab($request);
        $account->loadMissing([
            'customerAuthSetting',
            'subscription.plan',
            'subscription.paymentMethod',
        ]);
        $wallet = $wallets->walletFor($account);

        return view('accounts.sms-account', [
            'account' => $account,
            'wallet' => $wallet,
            'segmentPriceCents' => $pricing->segmentPriceCents($account),
            'serviceEnabled' => $settings->enabled(),
            'topUpPresetsCents' => $settings->topUpPresetsCents(),
            'paymentMethod' => $account->subscription?->paymentMethod,
            'monthlyUsageCents' => $account->smsDeliveries()
                ->whereNotNull('accepted_at')
                ->where('accepted_at', '>=', now($account->timezone ?: config('app.timezone'))->startOfMonth()->utc())
                ->sum('amount_cents'),
            'activeReportTab' => $activeReportTab,
            ...$this->reportData($account, $activeReportTab),
            'platformView' => true,
        ]);
    }

    public function adjust(
        AdjustSmsWalletRequest $request,
        Account $account,
        SmsWalletService $wallets,
    ): RedirectResponse {
        $wallets->adjust(
            $wallets->walletFor($account),
            $request->amountCents(),
            $request->user(),
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('platform.accounts.sms-account.show', $account)
            ->with('status', __('app.sms_wallet_adjustment_saved'));
    }

    private function activeReportTab(Request $request): string
    {
        $tab = $request->string('tab')->toString();

        if (in_array($tab, ['ledger', 'top-ups', 'deliveries'], true)) {
            return $tab;
        }

        return match (true) {
            $request->has('top_ups_page') => 'top-ups',
            $request->has('deliveries_page') => 'deliveries',
            default => 'ledger',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function reportData(Account $account, string $activeReportTab): array
    {
        return match ($activeReportTab) {
            'top-ups' => [
                'topUpPayments' => $account->smsTopUpPayments()
                    ->with('fiscalReceipt')
                    ->latest('id')
                    ->paginate(25, ['*'], 'top_ups_page')
                    ->withQueryString()
                    ->appends(['tab' => 'top-ups']),
            ],
            'deliveries' => [
                'deliveries' => $account->smsDeliveries()
                    ->latest('id')
                    ->paginate(25, ['*'], 'deliveries_page')
                    ->withQueryString()
                    ->appends(['tab' => 'deliveries']),
            ],
            default => [
                'ledgerEntries' => $account->smsWalletLedgerEntries()
                    ->with(['actor', 'reference'])
                    ->latest('id')
                    ->paginate(25, ['*'], 'ledger_page')
                    ->withQueryString()
                    ->appends(['tab' => 'ledger']),
            ],
        };
    }
}
