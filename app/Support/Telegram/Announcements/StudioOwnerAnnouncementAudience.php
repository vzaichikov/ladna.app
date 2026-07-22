<?php

namespace App\Support\Telegram\Announcements;

use App\Models\Account;
use App\Models\TelegramChatAuthorization;
use App\Models\User;
use Illuminate\Support\Collection;
use JsonException;

final readonly class StudioOwnerAnnouncementAudience
{
    /**
     * @param  Collection<int, array{authorization: TelegramChatAuthorization, owner: User, account: Account, locale: string, resolution: string}>  $recipients
     * @param  array{owners_without_authorized_chat: int, owners_without_phone: int, owners_with_alerts_disabled: int, ignored_non_owner_authorizations: int}  $excluded
     * @param  array<int, string>  $integrityErrors
     */
    public function __construct(
        public Collection $recipients,
        public array $excluded,
        public array $integrityErrors,
    ) {}

    /**
     * @throws JsonException
     */
    public function hash(): string
    {
        $snapshot = $this->recipients
            ->map(fn (array $recipient): array => [
                'account_id' => $recipient['authorization']->account_id,
                'authorization_id' => $recipient['authorization']->id,
                'chat_id' => $recipient['authorization']->telegram_chat_id,
                'installation_id' => $recipient['authorization']->telegram_bot_installation_id,
                'locale' => $recipient['locale'],
                'owner_user_id' => $recipient['owner']->id,
                'resolution' => $recipient['resolution'],
            ])
            ->sortBy(fn (array $recipient): string => implode(':', [
                $recipient['account_id'],
                $recipient['authorization_id'],
                $recipient['chat_id'],
                $recipient['owner_user_id'],
            ]))
            ->values()
            ->all();

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function ownerCount(): int
    {
        return $this->recipients
            ->unique(fn (array $recipient): string => $recipient['authorization']->account_id.':'.$recipient['owner']->id)
            ->count();
    }

    /**
     * @return array{owners: array<int, array{owner_name: string, studio_name: string, locale: string}>, omitted: int}
     */
    public function ownerReport(int $limit): array
    {
        $owners = $this->recipients
            ->unique(fn (array $recipient): string => $recipient['authorization']->account_id.':'.$recipient['owner']->id)
            ->map(fn (array $recipient): array => [
                'owner_name' => $recipient['owner']->name,
                'studio_name' => $recipient['account']->name,
                'locale' => $recipient['locale'],
            ])
            ->values();

        return [
            'owners' => $owners->take($limit)->all(),
            'omitted' => max(0, $owners->count() - $limit),
        ];
    }
}
