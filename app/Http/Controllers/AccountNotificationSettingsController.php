<?php

namespace App\Http\Controllers;

use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\TrainerNotificationSetting;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
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
            $installation = $account->telegramBotInstallations()
                ->where('profile', TelegramBotProfile::Customer->value)
                ->first();
            $botLink = $installation?->bot_username
                ? 'https://t.me/'.ltrim($installation->bot_username, '@').'?start=ladna'
                : null;

            $viewData += [
                'telegramBotInstallation' => $installation,
                'telegramBotProfile' => $account->telegramBotProfiles()
                    ->where('profile', TelegramBotProfile::Customer->value)
                    ->first(),
                'telegramBotLink' => $botLink,
                'telegramBotQrSvg' => $botLink ? $this->qrCodeSvg($botLink) : null,
                'telegramBotActiveConnectionsCount' => $installation?->chatAuthorizations()
                    ->where('profile', TelegramBotProfile::Customer->value)
                    ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
                    ->count() ?? 0,
                'telegramBotLastMessage' => $installation?->messages()
                    ->where('profile', TelegramBotProfile::Customer->value)
                    ->latest('sent_at')
                    ->latest('id')
                    ->first(),
                'telegramBotLastUpdate' => $installation?->updates()
                    ->where('profile', TelegramBotProfile::Customer->value)
                    ->latest('received_at')
                    ->latest('id')
                    ->first(),
                'latestTelegramBotError' => $installation?->updates()
                    ->where('status', 'failed')
                    ->latest('id')
                    ->value('error_message'),
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

    private function qrCodeSvg(string $url): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }
}
