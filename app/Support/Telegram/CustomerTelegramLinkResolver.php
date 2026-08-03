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
    public const PlacementCustomerDashboard = 'customer_dashboard';

    public const PlacementPublicStudio = 'public_studio';

    public const PlacementPublicContacts = 'public_contacts';

    public const PlacementSettingsKey = 'bot_link_placements';

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

    public function linkForPlacement(Account $account, string $placement): ?string
    {
        if (! in_array($placement, self::placements(), true)) {
            return null;
        }

        $profile = $account->telegramBotProfiles()
            ->where('profile', TelegramBotProfile::Customer->value)
            ->where('mode', TelegramBotMode::Simple->value)
            ->where('is_enabled', true)
            ->first(['settings']);

        if (! $profile || ! (bool) data_get($profile->settings, self::PlacementSettingsKey.'.'.$placement, false)) {
            return null;
        }

        $username = $account->telegramBotInstallations()
            ->where('profile', TelegramBotProfile::Customer->value)
            ->where('is_enabled', true)
            ->whereNotNull('encrypted_token')
            ->whereNotNull('bot_username')
            ->value('bot_username');

        if (! is_string($username) || trim($username) === '') {
            return null;
        }

        return 'https://t.me/'.ltrim(trim($username), '@').'?start=ladna';
    }

    /**
     * @return array<int, string>
     */
    public static function placements(): array
    {
        return [
            self::PlacementCustomerDashboard,
            self::PlacementPublicStudio,
            self::PlacementPublicContacts,
        ];
    }
}
