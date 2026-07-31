<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountSmsSendingSettingsRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;

class AccountSmsSendingSettingsController extends Controller
{
    public function update(
        UpdateAccountSmsSendingSettingsRequest $request,
        Account $account,
    ): RedirectResponse {
        $account->customerAuthSetting()->updateOrCreate(
            ['account_id' => $account->id],
            $request->payload(),
        );

        return redirect()
            ->route('dashboard.accounts.integrations.index', [
                'account' => $account,
                'tab' => 'messaging',
            ])
            ->with('status', __('app.sms_sending_settings_updated'));
    }
}
