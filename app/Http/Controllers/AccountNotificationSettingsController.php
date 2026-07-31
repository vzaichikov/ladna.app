<?php

namespace App\Http\Controllers;

use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\TrainerNotificationSetting;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountNotificationSettingsController extends Controller
{
    public function edit(
        Request $request,
        Account $account,
        CustomerAuthAvailability $customerAuthAvailability,
    ): View {
        $this->authorize('update', $account);

        $activeTab = in_array($request->query('tab'), ['customers', 'trainers', 'telegram'], true)
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
        } elseif ($activeTab === 'telegram') {
            $viewData += [
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
        } else {
            $viewData += [
                'customerNotificationSetting' => $account->customerNotificationSetting()->first(),
                'customerAuthSetting' => $customerAuthAvailability->settingsFor($account),
                'customerAuthReadiness' => $customerAuthAvailability->readinessFor($account),
            ];
        }

        return view('accounts.notification-settings', $viewData);
    }
}
