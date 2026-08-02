<?php

namespace App\Support\Telegram;

use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\TelegramChatAuthorization;

class CustomerTelegramLinkResolver
{
    public function activeAuthorization(Account $account, Customer $customer): ?TelegramChatAuthorization
    {
        if (! $this->botEnabled($account)) {
            return null;
        }

        return TelegramChatAuthorization::query()
            ->with('installation')
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer)
            ->where('profile', TelegramBotProfile::Customer->value)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->whereHas('installation', fn ($query) => $query
                ->where('account_id', $account->id)
                ->where('profile', TelegramBotProfile::Customer->value)
                ->where('is_enabled', true)
                ->whereNotNull('encrypted_token'))
            ->latest('authorized_at')
            ->first();
    }

    public function botEnabled(Account $account): bool
    {
        $profileEnabled = $account->telegramBotProfiles()
            ->where('profile', TelegramBotProfile::Customer->value)
            ->where('mode', TelegramBotMode::Simple->value)
            ->where('is_enabled', true)
            ->exists();

        return $profileEnabled && $account->telegramBotInstallations()
            ->where('profile', TelegramBotProfile::Customer->value)
            ->where('is_enabled', true)
            ->whereNotNull('encrypted_token')
            ->exists();
    }
}
