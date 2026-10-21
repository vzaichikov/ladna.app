<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSmsAlertSettingsRequest;
use App\Models\Account;
use App\Support\Sms\SmsWalletService;
use Illuminate\Http\RedirectResponse;

class AccountSmsAlertSettingsController extends Controller
{
    public function update(UpdateSmsAlertSettingsRequest $request, Account $account, SmsWalletService $wallets): RedirectResponse
    {
        $this->authorize('view', $account);
        $wallets->walletFor($account)->update([
            'telegram_alerts_enabled' => $request->boolean('telegram_alerts_enabled'),
        ]);

        return to_route('dashboard.accounts.sms-account.show', $account)
            ->with('status', __('app.sms_telegram_alerts_saved'));
    }
}
