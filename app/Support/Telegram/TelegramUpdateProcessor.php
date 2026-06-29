<?php

namespace App\Support\Telegram;

use App\Enums\AiConversationMessageRole;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Enums\TelegramUpdateStatus;
use App\Models\AiConversation;
use App\Models\PlatformAiSetting;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use Throwable;

class TelegramUpdateProcessor
{
    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly TelegramContactAuthorizer $contactAuthorizer,
        private readonly TelegramOwnerResponder $ownerResponder,
    ) {}

    public function process(int $telegramUpdateId): void
    {
        $telegramUpdate = TelegramUpdate::with(['account', 'installation.account'])->find($telegramUpdateId);

        if (! $telegramUpdate) {
            return;
        }

        $telegramUpdate->update(['status' => TelegramUpdateStatus::Processing->value]);

        try {
            $this->processCallbackQuery($telegramUpdate)
                || $this->processMessage($telegramUpdate);
            $telegramUpdate->update([
                'status' => TelegramUpdateStatus::Processed->value,
                'processed_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            $telegramUpdate->update([
                'status' => TelegramUpdateStatus::Failed->value,
                'error_message' => $throwable->getMessage(),
                'processed_at' => now(),
            ]);
        }
    }

    private function processCallbackQuery(TelegramUpdate $telegramUpdate): bool
    {
        $callbackQuery = data_get($telegramUpdate->payload, 'callback_query');

        if (! is_array($callbackQuery)) {
            return false;
        }

        $installation = $telegramUpdate->installation;
        $chatId = (string) data_get($callbackQuery, 'message.chat.id');
        $authorization = $this->contactAuthorizer->authorizeSelection($installation, $callbackQuery);

        $this->telegramClient->answerCallbackQuery($installation, (string) data_get($callbackQuery, 'id'));

        if (! $authorization) {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_authorization_failed'));

            return true;
        }

        $telegramUpdate->update(['account_id' => $authorization->account_id]);
        $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_authorized'), [], $authorization->account_id, $authorization);

        return true;
    }

    private function processMessage(TelegramUpdate $telegramUpdate): bool
    {
        $message = data_get($telegramUpdate->payload, 'message');

        if (! is_array($message)) {
            $telegramUpdate->update(['status' => TelegramUpdateStatus::Ignored->value, 'processed_at' => now()]);

            return true;
        }

        $installation = $telegramUpdate->installation;
        $chatId = (string) data_get($message, 'chat.id');
        $text = trim((string) data_get($message, 'text', ''));

        $inboundMessage = TelegramMessage::create([
            'account_id' => $telegramUpdate->account_id,
            'telegram_bot_installation_id' => $installation->id,
            'telegram_update_id' => $telegramUpdate->id,
            'profile' => $installation->profile->value,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => (string) data_get($message, 'message_id'),
            'telegram_user_id' => (string) data_get($message, 'from.id'),
            'direction' => 'inbound',
            'message_type' => data_get($message, 'contact') ? 'contact' : 'text',
            'text' => $text ?: null,
            'payload' => $message,
            'sent_at' => now(),
        ]);

        if (data_get($message, 'contact')) {
            $result = $this->contactAuthorizer->authorize($installation, $message);

            if (($result['status'] ?? null) === 'authorized' && ($result['authorization'] ?? null) instanceof TelegramChatAuthorization) {
                $authorization = $result['authorization'];
                $telegramUpdate->update(['account_id' => $authorization->account_id]);
                $inboundMessage->update([
                    'account_id' => $authorization->account_id,
                    'telegram_chat_authorization_id' => $authorization->id,
                ]);
                $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_authorized'), [], $authorization->account_id, $authorization);

                return true;
            }

            if (($result['status'] ?? null) === 'selection_required' && isset($result['selection'])) {
                $selection = $result['selection'];
                $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_choose_studio'), [
                    'reply_markup' => [
                        'inline_keyboard' => $selection->candidates
                            ->map(fn ($candidate): array => [[
                                'text' => $candidate->label,
                                'callback_data' => 'tg_select:'.$candidate->id,
                            ]])
                            ->values()
                            ->all(),
                    ],
                ]);

                return true;
            }

            $messageText = ($result['status'] ?? null) === 'not_found'
                ? __('app.telegram_unknown_phone_signup', ['url' => route('demo.signup.create')])
                : __('app.telegram_authorization_failed');
            $this->sendAndStore($telegramUpdate, $chatId, $messageText);

            return true;
        }

        $authorization = TelegramChatAuthorization::query()
            ->with('account')
            ->where('telegram_bot_installation_id', $installation->id)
            ->where('telegram_chat_id', $chatId)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->first();

        if (! $authorization) {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_share_contact_to_authorize'), [
                'reply_markup' => [
                    'keyboard' => [[
                        ['text' => __('app.telegram_share_phone_button'), 'request_contact' => true],
                    ]],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true,
                ],
            ]);

            return true;
        }

        $inboundMessage->update([
            'account_id' => $authorization->account_id,
            'telegram_chat_authorization_id' => $authorization->id,
        ]);
        $telegramUpdate->update(['account_id' => $authorization->account_id]);

        if ($installation->profile === TelegramBotProfile::Customer) {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_customer_bot_not_enabled'), [], $authorization->account_id, $authorization);

            return true;
        }

        if (! PlatformAiSetting::ownerAssistantEnabled()) {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_owner_bot_disabled'), [], $authorization->account_id, $authorization);

            return true;
        }

        $account = $authorization->account;
        $result = $this->ownerResponder->respond($account, $text, $authorization);
        $outboundMessage = $this->sendAndStore($telegramUpdate, $chatId, $result['response'], [], $account->id, $authorization);

        $this->recordConversation($authorization, $inboundMessage, $outboundMessage, $text, $result['response'], $result['rejected'], $result['used_ai']);

        return true;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function sendAndStore(TelegramUpdate $telegramUpdate, string $chatId, string $text, array $extra = [], ?int $accountId = null, ?TelegramChatAuthorization $authorization = null): TelegramMessage
    {
        $this->telegramClient->sendMessage($telegramUpdate->installation, $chatId, $text, $extra);

        return TelegramMessage::create([
            'account_id' => $accountId ?? $telegramUpdate->account_id,
            'telegram_bot_installation_id' => $telegramUpdate->telegram_bot_installation_id,
            'telegram_chat_authorization_id' => $authorization?->id,
            'telegram_update_id' => $telegramUpdate->id,
            'profile' => $telegramUpdate->profile->value,
            'telegram_chat_id' => $chatId,
            'direction' => 'outbound',
            'message_type' => 'text',
            'text' => $text,
            'payload' => $extra ?: null,
            'sent_at' => now(),
        ]);
    }

    private function recordConversation(
        TelegramChatAuthorization $authorization,
        TelegramMessage $inboundMessage,
        TelegramMessage $outboundMessage,
        string $input,
        string $response,
        bool $rejected,
        bool $usedAi,
    ): void {
        $conversation = AiConversation::firstOrCreate(
            [
                'account_id' => $authorization->account_id,
                'telegram_chat_authorization_id' => $authorization->id,
                'channel' => 'telegram_owner',
                'profile' => $authorization->profile->value,
                'status' => 'active',
            ],
            [
                'title' => __('app.telegram_owner_bot_title'),
                'last_message_at' => now(),
            ],
        );

        $conversation->messages()->create([
            'account_id' => $authorization->account_id,
            'telegram_message_id' => $inboundMessage->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => $input,
            'occurred_at' => now(),
        ]);

        $conversation->messages()->create([
            'account_id' => $authorization->account_id,
            'telegram_message_id' => $outboundMessage->id,
            'role' => $rejected ? AiConversationMessageRole::RejectedIntent->value : AiConversationMessageRole::Assistant->value,
            'content' => $response,
            'metadata' => ['used_ai' => $usedAi],
            'occurred_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);
    }
}
