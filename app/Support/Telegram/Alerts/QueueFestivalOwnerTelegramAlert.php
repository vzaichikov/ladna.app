<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\AccountRole;
use App\Enums\FestivalNotificationType;
use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertType;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalNotificationSetting;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use Illuminate\Support\Collection;

class QueueFestivalOwnerTelegramAlert
{
    public function __construct(
        private readonly TelegramAlertProducer $alerts,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(
        Account $account,
        FestivalEdition $edition,
        FestivalNotificationType $type,
        array $payload,
        string $eventKey,
        ?FestivalEntry $entry = null,
    ): int {
        if (! $this->scenarioIsEnabled($account, $type)) {
            return 0;
        }

        $authorizations = $this->connectedOwnerAuthorizations($account);
        if ($authorizations->isEmpty()) {
            return 0;
        }

        $entry?->loadMissing(['category', 'portalUser']);
        $ownerPayload = [
            'notification_type' => $type->value,
            'festival' => $edition->title,
            'entry' => $entry ? $entry->entry_name.' ('.$entry->code.')' : null,
            'applicant' => $entry?->portalUser?->displayName(),
            'category' => $entry?->category?->name,
            'staff_url' => $this->staffUrl($account, $edition, $type, $entry),
            ...$payload,
        ];
        unset($ownerPayload['action_url']);

        foreach ($authorizations as $authorization) {
            $this->alerts->queue(
                TelegramAlertType::FestivalUpdate,
                $account,
                TelegramAlertRecipientKind::StudioOwner,
                $ownerPayload,
                [
                    'telegram_bot_installation_id' => $authorization->telegram_bot_installation_id,
                    'telegram_chat_authorization_id' => $authorization->id,
                    'telegram_chat_id' => $authorization->telegram_chat_id,
                    'telegram_user_id' => $authorization->telegram_user_id,
                ],
                'festival-update:'.$eventKey.':authorization:'.$authorization->id,
            );
        }

        return $authorizations->count();
    }

    public function connectedOwnerCount(Account $account): int
    {
        return $this->connectedOwnerAuthorizations($account)->count();
    }

    private function scenarioIsEnabled(Account $account, FestivalNotificationType $type): bool
    {
        return FestivalNotificationSetting::query()
            ->whereBelongsTo($account)
            ->where('type', $type->value)
            ->where('notify_owner_telegram', true)
            ->exists();
    }

    /** @return Collection<int, TelegramChatAuthorization> */
    private function connectedOwnerAuthorizations(Account $account): Collection
    {
        $installation = TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('scope_id', 0)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('is_enabled', true)
            ->whereNotNull('encrypted_token')
            ->latest('updated_at')
            ->latest('id')
            ->first();
        if (! $installation) {
            return collect();
        }

        $ownerUserIds = $account->memberships()
            ->where('role', AccountRole::Owner->value)
            ->pluck('user_id');

        return TelegramChatAuthorization::query()
            ->where('account_id', $account->id)
            ->where('telegram_bot_installation_id', $installation->id)
            ->whereIn('user_id', $ownerUserIds)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->latest('authorized_at')
            ->latest('id')
            ->get()
            ->unique('telegram_chat_id')
            ->values();
    }

    private function staffUrl(Account $account, FestivalEdition $edition, FestivalNotificationType $type, ?FestivalEntry $entry): string
    {
        if ($entry) {
            return route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]);
        }

        if ($type === FestivalNotificationType::TicketsIssued) {
            return route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'sold']);
        }

        return route('dashboard.accounts.festivals.communication', [$account, $edition]);
    }
}
