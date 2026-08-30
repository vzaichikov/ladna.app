<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalPortalRole;
use App\Jobs\SendFestivalNotification;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalNotification;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicketOrder;
use App\Models\TelegramChatAuthorization;
use App\Support\Festivals\FestivalNotificationMessage;
use App\Support\Festivals\FestivalNotificationRenderer;
use App\Support\Festivals\FestivalNotificationScenarioSettings;
use App\Support\Telegram\Alerts\QueueFestivalOwnerTelegramAlert;
use Illuminate\Support\Facades\DB;

class FestivalNotificationOutbox
{
    public function __construct(
        private readonly FestivalNotificationRenderer $renderer,
        private readonly FestivalNotificationScenarioSettings $scenarioSettings,
        private readonly QueueFestivalOwnerTelegramAlert $ownerTelegramAlerts,
    ) {}

    /** @param array<string, mixed> $payload */
    public function queueForEntry(FestivalEntry $entry, string|FestivalNotificationType $type, array $payload, ?string $dedupeSuffix = null): ?FestivalNotification
    {
        $entry->loadMissing(['account', 'portalUser', 'edition']);
        $payload = [
            'festival' => $entry->edition->title,
            'entry_code' => $entry->code,
            'entry_name' => $entry->entry_name,
            'action_url' => route('festival.portal.entries.show', [$entry->account->slug, $entry]),
            ...$payload,
        ];

        return $this->queue(
            portalUser: $entry->portalUser,
            edition: $entry->edition,
            type: $type,
            payload: $payload,
            entry: $entry,
            dedupeSuffix: $dedupeSuffix,
        );
    }

    /** @param array<string, mixed> $payload */
    public function queue(FestivalPortalUser $portalUser, FestivalEdition $edition, string|FestivalNotificationType $type, array $payload, ?FestivalEntry $entry = null, ?string $dedupeSuffix = null): ?FestivalNotification
    {
        $type = $type instanceof FestivalNotificationType ? $type : FestivalNotificationType::from($type);
        abort_unless($portalUser->account_id === $edition->account_id, 404);
        $edition->loadMissing('account');

        if ($edition->account->isReadOnlyDemo()) {
            return null;
        }

        $payload = [
            ...$payload,
            'festival' => $edition->title,
        ];

        $dedupeBase = implode(':', [
            $type->value,
            $edition->id,
            $entry?->id ?? 0,
            $portalUser->id,
            $dedupeSuffix ?? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
        $message = $this->renderer->render($type, $portalUser->locale, $portalUser->displayName(), $payload);

        $notification = null;
        if (filter_var($portalUser->email, FILTER_VALIDATE_EMAIL)
            && $this->scenarioSettings->emailIsEnabled($edition->account_id, $type)) {
            $notification = $this->createChannel(
                channel: FestivalNotificationChannel::Email,
                dedupeBase: $dedupeBase,
                attributes: [
                    'account_id' => $edition->account_id,
                    'festival_portal_user_id' => $portalUser->id,
                    'festival_edition_id' => $edition->id,
                    'festival_entry_id' => $entry?->id,
                    'type' => $type,
                    'recipient_email' => $portalUser->email,
                    'recipient_phone' => $portalUser->phone,
                    'recipient_name' => $portalUser->displayName(),
                    'subject' => $message->subject,
                    'text' => $message->emailText(),
                    'payload' => $this->storedPayload($payload, $message),
                    'available_at' => now(),
                ],
            );
        }

        if ($this->scenarioSettings->smsIsEnabled($edition->account_id, $type)) {
            $smsNotification = $this->createChannel(
                channel: FestivalNotificationChannel::Sms,
                dedupeBase: $dedupeBase,
                attributes: [
                    'account_id' => $edition->account_id,
                    'festival_portal_user_id' => $portalUser->id,
                    'festival_edition_id' => $edition->id,
                    'festival_entry_id' => $entry?->id,
                    'type' => $type,
                    'recipient_email' => $portalUser->email,
                    'recipient_phone' => $portalUser->phone,
                    'recipient_name' => $portalUser->displayName(),
                    'subject' => $message->subject,
                    'text' => $message->smsText,
                    'payload' => $this->storedPayload($payload, $message),
                    'available_at' => now(),
                ],
            );
            $notification ??= $smsNotification;
        }

        $telegramAuthorization = $portalUser->role === FestivalPortalRole::Registrant
            && $this->scenarioSettings->telegramIsEnabled($edition->account_id, $type)
            ? $this->telegramAuthorization($portalUser, $edition, $type)
            : null;
        if ($telegramAuthorization) {
            $telegramNotification = $this->createChannel(
                channel: FestivalNotificationChannel::Telegram,
                dedupeBase: $dedupeBase,
                attributes: [
                    'account_id' => $edition->account_id,
                    'festival_portal_user_id' => $portalUser->id,
                    'festival_edition_id' => $edition->id,
                    'festival_entry_id' => $entry?->id,
                    'telegram_chat_authorization_id' => $telegramAuthorization->id,
                    'type' => $type,
                    'recipient_email' => $portalUser->email,
                    'recipient_phone' => $portalUser->phone,
                    'recipient_name' => $portalUser->displayName(),
                    'subject' => $message->subject,
                    'text' => $message->emailText(),
                    'payload' => $this->storedPayload($payload, $message),
                    'available_at' => now(),
                ],
            );
            $notification ??= $telegramNotification;
        }

        $this->ownerTelegramAlerts->execute(
            $edition->account,
            $edition,
            $type,
            $payload,
            $this->ownerEventKey($type, $edition, $entry, $payload, $dedupeSuffix),
            $entry,
        );

        return $notification;
    }

    /** @param array<string, mixed> $payload */
    public function queueForTicketOrder(FestivalTicketOrder $order, array $payload, ?string $dedupeSuffix = null): ?FestivalNotification
    {
        $order->loadMissing(['account', 'edition', 'portalUser']);
        abort_unless($order->account_id === $order->edition->account_id, 404);

        if ($order->account->isReadOnlyDemo()) {
            return null;
        }

        $guest = $order->portalUser?->role === FestivalPortalRole::Guest
            && $order->portalUser->account_id === $order->account_id
            ? $order->portalUser
            : null;
        $type = FestivalNotificationType::TicketsIssued;
        unset($payload['action_url']);
        $payload = [
            ...$payload,
            'festival' => $order->edition->title,
            'order_id' => $order->order_id,
        ];
        $message = $this->renderer->render($type, $order->locale, $order->buyer_name, $payload);
        $dedupeBase = implode(':', [$type->value, $order->festival_edition_id, 'order', $order->id, $dedupeSuffix ?? 'issued']);
        $attributes = [
            'account_id' => $order->account_id,
            'festival_portal_user_id' => $guest?->id,
            'festival_edition_id' => $order->festival_edition_id,
            'festival_ticket_order_id' => $order->id,
            'type' => $type,
            'recipient_email' => $order->buyer_email,
            'recipient_phone' => $order->buyer_phone,
            'recipient_name' => $order->buyer_name,
            'subject' => $message->subject,
            'payload' => $this->storedPayload($payload, $message),
            'available_at' => now(),
        ];
        $notification = null;
        if (filter_var($order->buyer_email, FILTER_VALIDATE_EMAIL)
            && $this->scenarioSettings->emailIsEnabled($order->account_id, $type)) {
            $notification = $this->createChannel(FestivalNotificationChannel::Email, $dedupeBase, [
                ...$attributes,
                'text' => $message->emailText(),
            ]);
        }

        if (filled($order->buyer_phone) && $this->scenarioSettings->smsIsEnabled($order->account_id, $type)) {
            $smsNotification = $this->createChannel(FestivalNotificationChannel::Sms, $dedupeBase, [
                ...$attributes,
                'text' => $message->smsText,
            ]);
            $notification ??= $smsNotification;
        }

        $telegramAuthorization = $guest && $this->scenarioSettings->telegramIsEnabled($order->account_id, $type)
            ? $this->telegramAuthorization($guest, $order->edition, $type)
            : null;
        if ($telegramAuthorization) {
            $telegramNotification = $this->createChannel(FestivalNotificationChannel::Telegram, $dedupeBase, [
                ...$attributes,
                'telegram_chat_authorization_id' => $telegramAuthorization->id,
                'text' => $message->emailText(),
            ]);
            $notification ??= $telegramNotification;
        }

        $this->ownerTelegramAlerts->execute(
            $order->account,
            $order->edition,
            $type,
            [
                ...$payload,
                'applicant' => $order->buyer_name,
            ],
            'tickets-issued:'.$order->id.':'.($dedupeSuffix ?? 'issued'),
        );

        return $notification;
    }

    public function queueForEntrancePasses(FestivalPortalUser $portalUser, FestivalEdition $edition, int $passesCount, string $dedupeSuffix): ?FestivalNotification
    {
        abort_unless($portalUser->account_id === $edition->account_id, 404);
        $edition->loadMissing('account');

        if ($edition->account->isReadOnlyDemo()
            || ! filter_var($portalUser->email, FILTER_VALIDATE_EMAIL)
            || ! $this->scenarioSettings->emailIsEnabled($edition->account_id, FestivalNotificationType::EntrancePassesIssued)) {
            return null;
        }

        $type = FestivalNotificationType::EntrancePassesIssued;
        $payload = [
            'festival' => $edition->title,
            'passes_count' => $passesCount,
            'action_url' => route('festival.portal.tickets.index', $portalUser->account->slug),
        ];
        $message = $this->renderer->render($type, $portalUser->locale, $portalUser->displayName(), $payload);
        $dedupeBase = implode(':', [$type->value, $edition->id, $portalUser->id, $dedupeSuffix]);

        return $this->createChannel(FestivalNotificationChannel::Email, $dedupeBase, [
            'account_id' => $edition->account_id,
            'festival_portal_user_id' => $portalUser->id,
            'festival_edition_id' => $edition->id,
            'type' => $type,
            'recipient_email' => $portalUser->email,
            'recipient_phone' => $portalUser->phone,
            'recipient_name' => $portalUser->displayName(),
            'subject' => $message->subject,
            'text' => $message->emailText(),
            'payload' => $this->storedPayload($payload, $message),
            'available_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createChannel(FestivalNotificationChannel $channel, string $dedupeBase, array $attributes): FestivalNotification
    {
        $notification = FestivalNotification::query()->firstOrCreate([
            'dedupe_key' => $dedupeBase.':'.$channel->value,
        ], [
            ...$attributes,
            'channel' => $channel,
        ]);

        if ($notification->wasRecentlyCreated) {
            DB::afterCommit(fn () => SendFestivalNotification::dispatch($notification->id));
        }

        return $notification;
    }

    private function telegramAuthorization(FestivalPortalUser $portalUser, FestivalEdition $edition, FestivalNotificationType $type): ?TelegramChatAuthorization
    {
        if ($type->isOptional()) {
            $preference = $portalUser->notificationPreferences()
                ->where('type', $type->value)
                ->value('is_enabled');

            if ($preference !== null && ! $preference) {
                return null;
            }
        }

        return TelegramChatAuthorization::query()
            ->where('telegram_chat_authorizations.account_id', $portalUser->account_id)
            ->where('telegram_chat_authorizations.profile', 'festival')
            ->where('telegram_chat_authorizations.status', 'authorized')
            ->whereHas('installation', fn ($query) => $query
                ->where('account_id', $portalUser->account_id)
                ->where('scope_type', 'festival_series')
                ->where('scope_id', $edition->festival_series_id)
                ->where('profile', 'festival')
                ->where('is_enabled', true))
            ->whereHas('festivalPortalLinks', fn ($query) => $query
                ->where('account_id', $portalUser->account_id)
                ->where('festival_portal_user_id', $portalUser->id))
            ->first();
    }

    /** @param array<string, mixed> $payload */
    private function ownerEventKey(FestivalNotificationType $type, FestivalEdition $edition, ?FestivalEntry $entry, array $payload, ?string $dedupeSuffix): string
    {
        return implode(':', [
            $type->value,
            $edition->id,
            $entry?->id ?? 0,
            $dedupeSuffix ?? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function storedPayload(array $payload, FestivalNotificationMessage $message): array
    {
        return [
            ...$payload,
            'greeting' => $message->greeting,
            'lines' => $message->lines,
            'action_label' => $message->actionLabel,
            'action_url' => $message->actionUrl,
        ];
    }
}
