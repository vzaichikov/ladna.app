<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Sms\AccountSmsPricing;
use App\Support\Sms\SmsServiceSettings;
use App\Support\Sms\SmsWalletService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountSmsAccountController extends Controller
{
    public function show(
        Request $request,
        Account $account,
        SmsWalletService $wallets,
        AccountSmsPricing $pricing,
        SmsServiceSettings $settings,
    ): View {
        $this->authorize('view', $account);
        abort_unless($account->isOwnedBy($request->user()), 403);

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
            'ledgerEntries' => $account->smsWalletLedgerEntries()
                ->with(['actor', 'reference'])
                ->latest('id')
                ->limit(100)
                ->get(),
            'topUpPayments' => $account->smsTopUpPayments()
                ->with('fiscalReceipt')
                ->latest('id')
                ->limit(50)
                ->get(),
            'deliveries' => $account->smsDeliveries()
                ->latest('id')
                ->limit(50)
                ->get(),
            'platformView' => false,
        ]);
    }
}
