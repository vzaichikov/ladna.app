<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTrainerNotificationSettingsRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class TrainerNotificationSettingsController extends Controller
{
    public function update(UpdateTrainerNotificationSettingsRequest $request, Account $account): RedirectResponse
    {
        $payload = $request->payload();

        DB::transaction(function () use ($account, $payload): void {
            $account->update([
                'enable_telegram_alerts' => $payload['enable_telegram_alerts'],
            ]);

            $account->trainerNotificationSetting()->updateOrCreate(
                ['account_id' => $account->id],
                ['trainer_assignment_enabled' => $payload['trainer_assignment_enabled']],
            );
        });

        return redirect()
            ->route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'trainers'])
            ->with('status', __('app.trainer_notification_settings_updated'));
    }
}
