<?php

namespace App\Support\Festivals;

use App\Enums\FestivalPortalRole;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use Illuminate\Database\Eloquent\Builder;

class FestivalTelegramAuthorizationResolver
{
    public function installation(FestivalSeries $series, bool $enabled = true): ?TelegramBotInstallation
    {
        return TelegramBotInstallation::query()
            ->where('account_id', $series->account_id)
            ->where('scope_type', 'festival_series')
            ->where('scope_id', $series->id)
            ->where('profile', TelegramBotProfile::Festival->value)
            ->when($enabled, fn (Builder $query): Builder => $query->where('is_enabled', true))
            ->first();
    }

    public function forTelegramUser(FestivalSeries $series, TelegramBotInstallation $installation, string $telegramUserId): ?TelegramChatAuthorization
    {
        if (! $this->installationMatches($series, $installation) || preg_match('/^\d+$/', $telegramUserId) !== 1) {
            return null;
        }

        return TelegramChatAuthorization::query()
            ->where('account_id', $series->account_id)
            ->where('telegram_bot_installation_id', $installation->id)
            ->where('profile', TelegramBotProfile::Festival->value)
            ->where('telegram_user_id', $telegramUserId)
            ->where('telegram_chat_id', $telegramUserId)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->first();
    }

    public function byId(FestivalSeries $series, int $authorizationId): ?TelegramChatAuthorization
    {
        $installation = $this->installation($series);

        if (! $installation) {
            return null;
        }

        return TelegramChatAuthorization::query()
            ->whereKey($authorizationId)
            ->where('account_id', $series->account_id)
            ->where('telegram_bot_installation_id', $installation->id)
            ->where('profile', TelegramBotProfile::Festival->value)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->first();
    }

    public function linkedPortalUser(TelegramChatAuthorization $authorization, FestivalPortalRole $role): ?FestivalPortalUser
    {
        $matches = FestivalPortalUser::query()
            ->where('festival_portal_users.account_id', $authorization->account_id)
            ->where('festival_portal_users.role', $role->value)
            ->where('festival_portal_users.is_active', true)
            ->whereHas('telegramFestivalLinks', fn (Builder $query): Builder => $query
                ->where('telegram_chat_authorization_id', $authorization->id)
                ->where('account_id', $authorization->account_id))
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function installationMatches(FestivalSeries $series, TelegramBotInstallation $installation): bool
    {
        return (int) $installation->account_id === (int) $series->account_id
            && $installation->scope_type === 'festival_series'
            && (int) $installation->scope_id === (int) $series->id
            && $installation->profile === TelegramBotProfile::Festival
            && $installation->is_enabled;
    }
}
