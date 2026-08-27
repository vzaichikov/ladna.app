<?php

namespace App\Support\Telegram;

use App\Enums\AccountMode;
use App\Enums\AccountStatus;
use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\FestivalNotification;
use App\Models\FestivalSeries;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Support\Festivals\FestivalTelegramAuthorizationResolver;
use App\Support\Festivals\FestivalTelegramIdentityLinker;
use App\Support\PhoneNumberNormalizer;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FestivalTelegramUpdateProcessor
{
    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly PhoneNumberNormalizer $phones,
        private readonly AccountSubscriptionAccess $subscriptionAccess,
        private readonly FestivalTelegramIdentityLinker $identityLinker,
        private readonly FestivalTelegramAuthorizationResolver $authorizations,
    ) {}

    public function handle(TelegramUpdate $telegramUpdate): bool
    {
        $telegramUpdate->loadMissing('installation.account');
        $installation = $telegramUpdate->installation;
        $account = $installation?->account;

        if (! $installation || ! $account || $installation->profile !== TelegramBotProfile::Festival) {
            return false;
        }

        $series = FestivalSeries::query()
            ->whereKey($installation->scope_id)
            ->where('account_id', $account->id)
            ->where('is_active', true)
            ->first();

        if (! $series || ! $this->accountCanUseBot($account) || ! $installation->is_enabled) {
            return true;
        }

        if ($this->handleMembershipUpdate($telegramUpdate, $series)) {
            return true;
        }

        $message = data_get($telegramUpdate->payload, 'message');

        if (! is_array($message)) {
            return false;
        }

        $chatId = (string) data_get($message, 'chat.id', '');
        $chatType = (string) data_get($message, 'chat.type', '');
        $telegramUserId = (string) data_get($message, 'from.id', '');

        if ($chatType !== 'private' || $chatId === '' || $telegramUserId === '' || ! hash_equals($chatId, $telegramUserId)) {
            return true;
        }

        $rateLimitKey = 'telegram-festival:'.$installation->id.':'.$chatId;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 40)) {
            return true;
        }

        RateLimiter::hit($rateLimitKey, 60);
        $lockKey = 'telegram-festival-chat:'.hash('sha256', $installation->id.':'.$chatId);

        return Cache::lock($lockKey, 30)->block(5, function () use ($telegramUpdate, $series, $message, $chatId, $telegramUserId): bool {
            $authorization = $this->authorizations->forTelegramUser($series, $telegramUpdate->installation, $telegramUserId);
            $this->storeInbound($telegramUpdate, $authorization, $message);
            $command = $this->command((string) data_get($message, 'text', ''));

            if ($command === 'unlink') {
                if ($authorization) {
                    $this->revoke($authorization);
                }
                $this->send($telegramUpdate, $chatId, __('app.festival_telegram_unlinked', locale: $this->locale($series, $message)), [], $authorization);

                return true;
            }

            $contact = data_get($message, 'contact');
            if (is_array($contact)) {
                $contactUserId = (string) data_get($contact, 'user_id', '');

                if ($contactUserId === '' || ! hash_equals($telegramUserId, $contactUserId)) {
                    $this->requestContact($telegramUpdate, $series, $chatId, __('app.festival_telegram_contact_must_be_own', locale: $this->locale($series, $message)));

                    return true;
                }

                $phone = $this->phones->normalize((string) data_get($contact, 'phone_number', ''), $series->account->country_code ?? 'UA');
                if (! $this->phones->isValid($phone, $series->account->country_code ?? 'UA')) {
                    $this->requestContact($telegramUpdate, $series, $chatId, __('app.telegram_customer_phone_invalid', locale: $this->locale($series, $message)));

                    return true;
                }

                try {
                    $linked = $this->identityLinker->authorizeRegistrant(
                        $series,
                        $telegramUpdate->installation,
                        $chatId,
                        $telegramUserId,
                        $phone,
                        [
                            'first_name' => (string) data_get($message, 'from.first_name', ''),
                            'last_name' => (string) data_get($message, 'from.last_name', ''),
                            'username' => (string) data_get($message, 'from.username', ''),
                            'language_code' => (string) data_get($message, 'from.language_code', ''),
                        ],
                    );
                    $this->sendOpenApp(
                        $telegramUpdate,
                        $series,
                        $chatId,
                        __('app.festival_telegram_welcome', ['name' => $linked['registrant']->displayName()], $this->locale($series, $message)),
                        $linked['authorization'],
                    );
                } catch (ValidationException $exception) {
                    $this->send($telegramUpdate, $chatId, (string) collect($exception->errors())->flatten()->first(), [], $authorization);
                }

                return true;
            }

            if ($authorization) {
                $this->sendOpenApp($telegramUpdate, $series, $chatId, __('app.festival_telegram_open_prompt', locale: $this->locale($series, $message)), $authorization);
            } else {
                $this->requestContact($telegramUpdate, $series, $chatId);
            }

            return true;
        });
    }

    private function requestContact(TelegramUpdate $update, FestivalSeries $series, string $chatId, ?string $message = null): void
    {
        $this->send($update, $chatId, $message ?: __('app.festival_telegram_contact_prompt', locale: $series->account->default_language), [
            'reply_markup' => [
                'keyboard' => [[[
                    'text' => __('app.festival_telegram_share_phone', locale: $series->account->default_language),
                    'request_contact' => true,
                ]]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
        ]);
    }

    private function sendOpenApp(TelegramUpdate $update, FestivalSeries $series, string $chatId, string $message, TelegramChatAuthorization $authorization): void
    {
        $locale = $this->localeForAuthorization($series, $authorization);
        $this->send($update, $chatId, $message, [
            'reply_markup' => ['remove_keyboard' => true],
        ], $authorization);
        $this->send($update, $chatId, __('app.festival_telegram_open_app', locale: $locale), [
            'reply_markup' => [
                'inline_keyboard' => [[[
                    'text' => __('app.festival_telegram_open_app', locale: $locale),
                    'web_app' => ['url' => route('public.festival-telegram.show', [$series->account->slug, $series->slug])],
                ]]],
            ],
        ], $authorization);
    }

    /** @param array<string, mixed> $extra */
    private function send(TelegramUpdate $update, string $chatId, string $text, array $extra = [], ?TelegramChatAuthorization $authorization = null): void
    {
        $response = $this->telegramClient->sendMessage($update->installation, $chatId, $text, [
            'disable_web_page_preview' => true,
            ...$extra,
        ]);

        if (! $this->telegramOk($response)) {
            throw new RuntimeException((string) ($response?->json('description') ?: 'Festival Telegram message delivery failed.'));
        }

        $telegramMessageId = filled($response?->json('result.message_id')) ? (string) $response?->json('result.message_id') : null;
        TelegramMessage::query()->firstOrCreate([
            'telegram_update_id' => $update->id,
            'direction' => 'outbound',
            'telegram_message_id' => $telegramMessageId,
        ], [
            'account_id' => $update->account_id,
            'telegram_bot_installation_id' => $update->telegram_bot_installation_id,
            'telegram_chat_authorization_id' => $authorization?->id,
            'profile' => TelegramBotProfile::Festival->value,
            'telegram_chat_id' => $chatId,
            'telegram_user_id' => $authorization?->telegram_user_id,
            'message_type' => 'text',
            'text' => $text,
            'payload' => $extra ?: null,
            'sent_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $message */
    private function storeInbound(TelegramUpdate $update, ?TelegramChatAuthorization $authorization, array $message): void
    {
        TelegramMessage::query()->firstOrCreate([
            'telegram_update_id' => $update->id,
            'direction' => 'inbound',
        ], [
            'account_id' => $update->account_id,
            'telegram_bot_installation_id' => $update->telegram_bot_installation_id,
            'telegram_chat_authorization_id' => $authorization?->id,
            'profile' => TelegramBotProfile::Festival->value,
            'telegram_chat_id' => (string) data_get($message, 'chat.id', ''),
            'telegram_message_id' => (string) data_get($message, 'message_id', ''),
            'telegram_user_id' => (string) data_get($message, 'from.id', ''),
            'message_type' => is_array(data_get($message, 'contact')) ? 'contact' : 'text',
            'text' => filled(data_get($message, 'text')) ? (string) data_get($message, 'text') : null,
            'payload' => $message,
            'sent_at' => now(),
        ]);
    }

    private function handleMembershipUpdate(TelegramUpdate $update, FestivalSeries $series): bool
    {
        $member = data_get($update->payload, 'my_chat_member');

        if (! is_array($member)) {
            return false;
        }

        $status = (string) data_get($member, 'new_chat_member.status', '');
        $chatId = (string) data_get($member, 'chat.id', '');

        if (data_get($member, 'chat.type') === 'private' && in_array($status, ['kicked', 'left'], true)) {
            $authorization = TelegramChatAuthorization::query()
                ->where('account_id', $series->account_id)
                ->where('telegram_bot_installation_id', $update->telegram_bot_installation_id)
                ->where('telegram_chat_id', $chatId)
                ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
                ->first();

            if ($authorization) {
                $this->revoke($authorization);
            }
        }

        return true;
    }

    private function revoke(TelegramChatAuthorization $authorization): void
    {
        DB::transaction(function () use ($authorization): void {
            $authorization->forceFill([
                'status' => TelegramChatAuthorizationStatus::Revoked,
                'revoked_at' => now(),
            ])->save();
            FestivalNotification::query()
                ->where('telegram_chat_authorization_id', $authorization->id)
                ->where('channel', FestivalNotificationChannel::Telegram->value)
                ->whereIn('status', [FestivalNotificationStatus::Pending->value, FestivalNotificationStatus::Failed->value])
                ->update([
                    'status' => FestivalNotificationStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'failure_reason' => 'festival_telegram_authorization_revoked',
                ]);
        });
    }

    private function accountCanUseBot(Account $account): bool
    {
        return $account->status === AccountStatus::Active
            && $account->mode === AccountMode::Live
            && $account->enable_festivals
            && ! $account->isReadOnlyDemo()
            && $this->subscriptionAccess->canUsePublicFeatures($account);
    }

    /** @param array<string, mixed> $message */
    private function locale(FestivalSeries $series, array $message): string
    {
        $candidate = Str::lower(Str::before((string) data_get($message, 'from.language_code', ''), '-'));

        return array_key_exists($candidate, config('ladna.locales', [])) ? $candidate : $series->account->default_language;
    }

    private function localeForAuthorization(FestivalSeries $series, TelegramChatAuthorization $authorization): string
    {
        return $authorization->festivalPortalLinks()->with('portalUser:id,locale')->first()?->portalUser?->locale
            ?: $series->account->default_language;
    }

    private function command(string $text): ?string
    {
        return preg_match('/^\/([a-z_]+)(?:@\w+)?(?:\s+.*)?$/i', trim($text), $matches) === 1
            ? Str::lower($matches[1])
            : null;
    }

    private function telegramOk(?Response $response): bool
    {
        return $response?->successful() === true && $response->json('ok') === true;
    }
}
