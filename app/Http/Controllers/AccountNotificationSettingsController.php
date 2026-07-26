<?php

namespace App\Http\Controllers;

use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\TrainerNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountNotificationSettingsController extends Controller
{
    public function edit(Request $request, Account $account): View
    {
        $this->authorize('update', $account);

        $activeTab = in_array($request->query('tab'), ['customers', 'trainers'], true)
            ? $request->query('tab')
            : 'customers';

        $viewData = [
            'account' => $account,
            'activeTab' => $activeTab,
        ];

        if ($activeTab === 'trainers') {
            $viewData['trainerNotificationSetting'] = $account->trainerNotificationSetting()->first()
                ?? new TrainerNotificationSetting([
                    'account_id' => $account->id,
                ]);
        } else {
            $viewData += [
                'customerNotificationSetting' => $account->customerNotificationSetting()->first(),
                'telegramBotProfilesList' => [TelegramBotProfile::Customer],
                'telegramBotModes' => [TelegramBotMode::Disabled, TelegramBotMode::Simple],
                'telegramBotInstallations' => $account->telegramBotInstallations()
                    ->where('profile', TelegramBotProfile::Customer->value)
                    ->get()
                    ->keyBy(fn ($installation): string => $installation->profile->value),
                'telegramBotProfiles' => $account->telegramBotProfiles()
                    ->where('profile', TelegramBotProfile::Customer->value)
                    ->get()
                    ->keyBy(fn ($profile): string => $profile->profile->value),
            ];
        }

        return view('accounts.notification-settings', $viewData);
    }
}
