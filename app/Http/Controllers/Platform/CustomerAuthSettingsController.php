<?php

namespace App\Http\Controllers\Platform;

use App\Enums\CustomerOtpSenderScope;
use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCustomerAuthSettingsRequest;
use App\Models\Account;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CustomerAuthSettingsController extends Controller
{
    public function edit(Account $account, CustomerAuthAvailability $availability): View
    {
        return view('platform.accounts.customer-auth', [
            'account' => $account,
            'settings' => $availability->settingsFor($account),
            'readiness' => $availability->readinessFor($account),
            'senderScopes' => CustomerOtpSenderScope::cases(),
            'smsProviders' => [
                IntegrationProvider::Turbosms,
                IntegrationProvider::Smsclub,
                IntegrationProvider::Sendpulse,
            ],
        ]);
    }

    public function update(UpdateCustomerAuthSettingsRequest $request, Account $account): RedirectResponse
    {
        $account->customerAuthSetting()->updateOrCreate(
            ['account_id' => $account->id],
            $request->payload(),
        );

        return redirect()
            ->route('platform.accounts.customer-auth.edit', $account)
            ->with('status', __('app.customer_auth_settings_updated'));
    }
}
