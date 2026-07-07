<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCustomerNotificationSettingsRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;

class CustomerNotificationSettingsController extends Controller
{
    public function update(UpdateCustomerNotificationSettingsRequest $request, Account $account): RedirectResponse
    {
        abort_unless($account->customerNotificationsEnabled(), 404);

        $account->customerNotificationSetting()->updateOrCreate(
            ['account_id' => $account->id],
            $request->payload(),
        );

        return redirect()
            ->route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'customer_notifications'])
            ->with('status', __('app.customer_notification_settings_updated'));
    }
}
