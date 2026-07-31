<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCustomerNotificationSettingsRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class CustomerNotificationSettingsController extends Controller
{
    public function update(UpdateCustomerNotificationSettingsRequest $request, Account $account): RedirectResponse
    {
        DB::transaction(function () use ($request, $account): void {
            $account->customerAuthSetting()->updateOrCreate(
                ['account_id' => $account->id],
                $request->customerAuthPayload(),
            );

            if ($account->customerNotificationsEnabled()) {
                $account->customerNotificationSetting()->updateOrCreate(
                    ['account_id' => $account->id],
                    $request->payload(),
                );
            }
        });

        return redirect()
            ->route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers'])
            ->with('status', __('app.customer_notification_settings_updated'));
    }
}
