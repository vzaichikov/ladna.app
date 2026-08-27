<?php

namespace App\Support\Telegram;

use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalPortalRole;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\FestivalNotification;
use App\Models\FestivalNotificationPreference;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicketOrder;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class FestivalTelegramNotificationSender
{
    public function __construct(private readonly TelegramClient $telegramClient) {}

    /** @return array{sent: bool, cancel_reason: string|null} */
    public function send(FestivalNotification $notification, Account $account): array
    {
        if ($notification->channel !== FestivalNotificationChannel::Telegram || ! $notification->telegram_chat_authorization_id) {
            return ['sent' => false, 'cancel_reason' => 'festival_telegram_delivery_invalid'];
        }

        $target = $this->target($notification, $account);
        if (! $target) {
            return ['sent' => false, 'cancel_reason' => 'festival_telegram_recipient_state_changed'];
        }

        if ($notification->type->isOptional()) {
            $preference = FestivalNotificationPreference::query()
                ->where('account_id', $account->id)
                ->where('festival_portal_user_id', $target['portal_user']->id)
                ->where('type', $notification->type->value)
                ->value('is_enabled');

            if ($preference !== null && ! $preference) {
                return ['sent' => false, 'cancel_reason' => 'festival_telegram_preference_disabled'];
            }
        }

        $existingMessage = TelegramMessage::query()
            ->where('telegram_chat_authorization_id', $target['authorization']->id)
            ->where('direction', 'outbound')
            ->where('payload->festival_notification_id', $notification->id)
            ->whereNotNull('sent_at')
            ->first();

        if ($existingMessage) {
            return ['sent' => true, 'cancel_reason' => null];
        }

        $text = Str::limit(collect([$notification->subject, $notification->text])->filter()->implode("\n\n"), 3900, '…');
        $extra = [
            'disable_web_page_preview' => true,
            'reply_markup' => [
                'inline_keyboard' => [[[
                    'text' => __('app.festival_telegram_open_app', locale: $target['portal_user']->locale),
                    'web_app' => ['url' => $target['mini_app_url']],
                ]]],
            ],
        ];
        $message = TelegramMessage::query()->create([
            'account_id' => $account->id,
            'telegram_bot_installation_id' => $target['authorization']->telegram_bot_installation_id,
            'telegram_chat_authorization_id' => $target['authorization']->id,
            'profile' => TelegramBotProfile::Festival->value,
            'telegram_chat_id' => $target['authorization']->telegram_chat_id,
            'telegram_user_id' => $target['authorization']->telegram_user_id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'text' => $text,
            'payload' => ['festival_notification_id' => $notification->id, 'action' => $target['action']],
        ]);
        $response = $this->telegramClient->sendMessage(
            $target['authorization']->installation,
            $target['authorization']->telegram_chat_id,
            $text,
            $extra,
        );

        if ($this->telegramOk($response)) {
            $message->forceFill([
                'telegram_message_id' => filled($response?->json('result.message_id')) ? (string) $response?->json('result.message_id') : null,
                'sent_at' => now(),
            ])->save();

            return ['sent' => true, 'cancel_reason' => null];
        }

        $message->delete();
        $errorCode = (int) $response?->json('error_code', $response?->status() ?? 0);

        if ($errorCode === 403) {
            DB::transaction(function () use ($target): void {
                $target['authorization']->forceFill([
                    'status' => TelegramChatAuthorizationStatus::Revoked,
                    'revoked_at' => now(),
                ])->save();
            });

            return ['sent' => false, 'cancel_reason' => 'festival_telegram_bot_blocked'];
        }

        if ($errorCode === 429 || $errorCode >= 500 || ! $response) {
            throw new RuntimeException((string) ($response?->json('description') ?: 'Festival Telegram delivery failed.'));
        }

        return ['sent' => false, 'cancel_reason' => 'festival_telegram_rejected'];
    }

    /**
     * @return array{authorization: TelegramChatAuthorization, portal_user: FestivalPortalUser, mini_app_url: string, action: string}|null
     */
    private function target(FestivalNotification $notification, Account $account): ?array
    {
        $notification->loadMissing(['edition.series', 'entry', 'ticketOrder', 'portalUser']);
        $edition = $notification->edition;
        $portalUser = $notification->portalUser;
        $expectedRole = $notification->type === FestivalNotificationType::TicketsIssued
            ? FestivalPortalRole::Guest
            : FestivalPortalRole::Registrant;

        if (! $edition
            || ! $edition->series
            || ! $portalUser
            || ! $portalUser->is_active
            || $portalUser->role !== $expectedRole
            || (int) $edition->account_id !== (int) $account->id
            || (int) $portalUser->account_id !== (int) $account->id) {
            return null;
        }

        $authorization = TelegramChatAuthorization::query()
            ->with('installation')
            ->whereKey($notification->telegram_chat_authorization_id)
            ->where('account_id', $account->id)
            ->where('profile', TelegramBotProfile::Festival->value)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->whereHas('installation', fn ($query) => $query
                ->where('account_id', $account->id)
                ->where('scope_type', 'festival_series')
                ->where('scope_id', $edition->festival_series_id)
                ->where('profile', TelegramBotProfile::Festival->value)
                ->where('is_enabled', true))
            ->whereHas('festivalPortalLinks', fn ($query) => $query
                ->where('account_id', $account->id)
                ->where('festival_portal_user_id', $portalUser->id))
            ->first();

        if (! $authorization || ! hash_equals((string) $authorization->telegram_user_id, (string) $portalUser->telegram_user_id)) {
            return null;
        }

        if ($notification->festival_entry_id
            && (! $notification->entry
                || (int) $notification->entry->account_id !== (int) $account->id
                || (int) $notification->entry->festival_edition_id !== (int) $edition->id
                || (int) $notification->entry->festival_portal_user_id !== (int) $portalUser->id)) {
            return null;
        }

        if ($notification->festival_ticket_order_id) {
            $order = FestivalTicketOrder::query()
                ->whereKey($notification->festival_ticket_order_id)
                ->where('account_id', $account->id)
                ->where('festival_edition_id', $edition->id)
                ->where('festival_portal_user_id', $portalUser->id)
                ->where('status', 'paid')
                ->first();
            if (! $order) {
                return null;
            }
        }

        [$action, $targetId] = match (true) {
            $notification->festival_ticket_order_id !== null => ['ticket_order', $notification->festival_ticket_order_id],
            $notification->festival_entry_id !== null => ['entry', $notification->festival_entry_id],
            default => ['dashboard', null],
        };
        $query = array_filter(['action' => $action, 'target_id' => $targetId], fn (mixed $value): bool => $value !== null);

        return [
            'authorization' => $authorization,
            'portal_user' => $portalUser,
            'mini_app_url' => route('public.festival-telegram.show', [$account->slug, $edition->series->slug]).'?'.http_build_query($query),
            'action' => $action,
        ];
    }

    private function telegramOk(?Response $response): bool
    {
        return $response?->successful() === true && $response->json('ok') === true;
    }
}
