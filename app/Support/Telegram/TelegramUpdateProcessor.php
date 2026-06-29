<?php

namespace App\Support\Telegram;

use App\Enums\AiConversationMessageRole;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Enums\TelegramUpdateStatus;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiPendingAction;
use App\Models\PlatformAiSetting;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Support\Ai\StudioAssistantActionExecutor;
use App\Support\Ai\StudioAssistantActionPlan;
use App\Support\Ai\StudioAssistantActionPlanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TelegramUpdateProcessor
{
    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly TelegramContactAuthorizer $contactAuthorizer,
        private readonly TelegramOwnerResponder $ownerResponder,
        private readonly StudioAssistantActionPlanner $actionPlanner,
        private readonly StudioAssistantActionExecutor $actionExecutor,
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
        $data = (string) data_get($callbackQuery, 'data', '');

        $this->telegramClient->answerCallbackQuery($installation, (string) data_get($callbackQuery, 'id'));

        if (str_starts_with($data, 'tg_select:')) {
            $authorization = $this->contactAuthorizer->authorizeSelection($installation, $callbackQuery);

            if (! $authorization) {
                $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_authorization_failed'));

                return true;
            }

            $telegramUpdate->update(['account_id' => $authorization->account_id]);
            $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_authorized'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization);

            return true;
        }

        $authorization = $this->authorizationForCallback($installation->id, $chatId);

        if (! $authorization) {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_authorization_failed'));

            return true;
        }

        $telegramUpdate->update(['account_id' => $authorization->account_id]);

        if (preg_match('/^tg_follow:(\d+):(\d+)$/', $data, $matches) === 1) {
            return $this->processFollowUpCallback($telegramUpdate, $authorization, $chatId, (int) $matches[1], (int) $matches[2], $callbackQuery);
        }

        if (preg_match('/^tg_action:(confirm|cancel):(\d+)$/', $data, $matches) === 1) {
            return $this->processActionCallback($telegramUpdate, $authorization, $chatId, $matches[1], (int) $matches[2]);
        }

        $this->sendAndStore($telegramUpdate, $chatId, __('app.assistant_action_unknown'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization);

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
                $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_authorized'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization);

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
            ->with(['account', 'user', 'trainer'])
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

        return $this->processAuthorizedOwnerText($telegramUpdate, $authorization, $inboundMessage, $chatId, $text);
    }

    private function processAuthorizedOwnerText(TelegramUpdate $telegramUpdate, TelegramChatAuthorization $authorization, TelegramMessage $inboundMessage, string $chatId, string $text): bool
    {
        $account = $authorization->account;
        $conversation = $this->conversationFor($authorization);
        $conversation->messages()->create([
            'account_id' => $authorization->account_id,
            'telegram_message_id' => $inboundMessage->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => $text,
            'occurred_at' => now(),
        ]);

        $plan = $authorization->user
            ? $this->actionPlanForText($account, $authorization, $conversation, $text)
            : null;

        if ($plan?->pendingAction || $plan?->handled) {
            $result = [
                'response' => $plan->message ?? __('app.assistant_pending_action_created'),
                'rejected' => false,
                'used_ai' => false,
                'metadata' => [
                    'used_ai' => false,
                    ...($plan->pendingAction ? ['pending_action_id' => $plan->pendingAction->id] : []),
                    ...$plan->metadata,
                ],
            ];
        } else {
            $result = $this->ownerResponder->respond(
                $account,
                $text,
                $authorization,
                function () use ($telegramUpdate, $chatId): void {
                    try {
                        $this->telegramClient->sendChatAction($telegramUpdate->installation, $chatId);
                    } catch (Throwable $throwable) {
                        report($throwable);
                    }
                },
            );
            $result['metadata'] = [
                'used_ai' => $result['used_ai'],
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'fallback_reason' => $result['fallback_reason'] ?? null,
                'follow_up_actions' => $result['follow_up_actions'] ?? [],
                'help_sources' => $result['help_sources'] ?? [],
            ];
        }

        $assistantMessage = $conversation->messages()->create([
            'account_id' => $authorization->account_id,
            'role' => $result['rejected'] ? AiConversationMessageRole::RejectedIntent->value : AiConversationMessageRole::Assistant->value,
            'content' => $result['response'],
            'metadata' => $result['metadata'],
            'occurred_at' => now(),
        ]);
        $outboundMessage = $this->sendAndStore(
            $telegramUpdate,
            $chatId,
            $result['response'],
            $this->assistantTelegramReplyMarkup($assistantMessage),
            $account->id,
            $authorization,
            $this->assistantTelegramText($result['response']),
        );

        $assistantMessage->update(['telegram_message_id' => $outboundMessage->id]);

        $conversation->update(['last_message_at' => now()]);

        return true;
    }

    private function processFollowUpCallback(TelegramUpdate $telegramUpdate, TelegramChatAuthorization $authorization, string $chatId, int $messageId, int $index, array $callbackQuery): bool
    {
        $message = AiConversationMessage::query()
            ->whereKey($messageId)
            ->where('account_id', $authorization->account_id)
            ->whereHas('conversation', fn ($query) => $query
                ->where('telegram_chat_authorization_id', $authorization->id)
                ->where('channel', 'telegram_owner')
                ->where('status', AiConversation::StatusActive))
            ->first();

        $followUps = data_get($message?->metadata, 'follow_up_actions', []);
        $text = is_array($followUps) ? ($followUps[$index] ?? null) : null;

        if (! is_string($text) || trim($text) === '') {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.assistant_action_unknown'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization);

            return true;
        }

        $inboundMessage = TelegramMessage::create([
            'account_id' => $authorization->account_id,
            'telegram_bot_installation_id' => $telegramUpdate->telegram_bot_installation_id,
            'telegram_chat_authorization_id' => $authorization->id,
            'telegram_update_id' => $telegramUpdate->id,
            'profile' => $telegramUpdate->profile->value,
            'telegram_chat_id' => $chatId,
            'telegram_user_id' => (string) data_get($callbackQuery, 'from.id'),
            'direction' => 'inbound',
            'message_type' => 'callback_query',
            'text' => $text,
            'payload' => $callbackQuery,
            'sent_at' => now(),
        ]);

        return $this->processAuthorizedOwnerText($telegramUpdate, $authorization, $inboundMessage, $chatId, $text);
    }

    private function processActionCallback(TelegramUpdate $telegramUpdate, TelegramChatAuthorization $authorization, string $chatId, string $mode, int $actionId): bool
    {
        $action = AiPendingAction::query()
            ->whereKey($actionId)
            ->where('account_id', $authorization->account_id)
            ->whereHas('conversation', fn ($query) => $query
                ->where('telegram_chat_authorization_id', $authorization->id)
                ->where('channel', 'telegram_owner'))
            ->first();

        if (! $action || ! $action->isPending()) {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.assistant_action_not_pending'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization);

            return true;
        }

        if ($mode === 'cancel') {
            $action->update([
                'status' => AiPendingAction::StatusCancelled,
                'cancelled_at' => now(),
            ]);

            $this->sendActionResult($telegramUpdate, $authorization, $chatId, $action, __('app.assistant_action_cancelled'), [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
            ]);

            return true;
        }

        if (! $authorization->user) {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.assistant_action_forbidden'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization);

            return true;
        }

        try {
            $result = $this->actionExecutor->execute($action, $authorization->user);
            $message = (string) ($result['message'] ?? __('app.assistant_action_executed'));
            $this->sendActionResult($telegramUpdate, $authorization, $chatId, $action->refresh(), $message, [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
                'result' => $result,
            ]);
        } catch (AuthorizationException $exception) {
            $this->sendAndStore($telegramUpdate, $chatId, $exception->getMessage() ?: __('app.assistant_action_forbidden'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization);
        } catch (ValidationException $exception) {
            $this->sendAndStore($telegramUpdate, $chatId, $this->validationMessage($exception), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function sendActionResult(TelegramUpdate $telegramUpdate, TelegramChatAuthorization $authorization, string $chatId, AiPendingAction $action, string $message, array $metadata): void
    {
        $outboundMessage = $this->sendAndStore($telegramUpdate, $chatId, $message, $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization);

        $action->conversation?->messages()->create([
            'account_id' => $authorization->account_id,
            'telegram_message_id' => $outboundMessage->id,
            'role' => AiConversationMessageRole::Tool->value,
            'content' => $message,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
        $action->conversation?->update(['last_message_at' => now()]);
    }

    private function validationMessage(ValidationException $exception): string
    {
        $errors = $exception->errors();
        $first = collect($errors)->flatten()->first();

        return is_string($first) && $first !== '' ? $first : $exception->getMessage();
    }

    private function authorizationForCallback(int $installationId, string $chatId): ?TelegramChatAuthorization
    {
        return TelegramChatAuthorization::query()
            ->with(['account', 'user', 'trainer'])
            ->where('telegram_bot_installation_id', $installationId)
            ->where('telegram_chat_id', $chatId)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->first();
    }

    private function actionPlanForText(Account $account, TelegramChatAuthorization $authorization, AiConversation $conversation, string $text): ?StudioAssistantActionPlan
    {
        if (! $authorization->user) {
            return null;
        }

        if ($this->isCreateBookingQuickAction($text)) {
            return $this->actionPlanner->startGroupBookingDialog($account, $authorization->user, $authorization->trainer, $conversation);
        }

        return $this->actionPlanner->plan($account, $authorization->user, $authorization->trainer, $conversation, $text);
    }

    private function isCreateBookingQuickAction(string $text): bool
    {
        return Str::of($text)->lower()->squish()->toString() === Str::of(__('app.telegram_quick_action_create_booking'))->lower()->squish()->toString();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function sendAndStore(TelegramUpdate $telegramUpdate, string $chatId, string $text, array $extra = [], ?int $accountId = null, ?TelegramChatAuthorization $authorization = null, ?string $telegramText = null): TelegramMessage
    {
        $this->telegramClient->sendMessage($telegramUpdate->installation, $chatId, $telegramText ?? $text, $extra);

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

    /**
     * @return array{parse_mode: string}
     */
    private function assistantTelegramFormatting(): array
    {
        return ['parse_mode' => 'HTML'];
    }

    /**
     * @return array<string, mixed>
     */
    private function assistantTelegramReplyMarkup(AiConversationMessage $message): array
    {
        $inlineKeyboard = $this->assistantInlineKeyboard($message);

        if ($inlineKeyboard !== []) {
            return [
                ...$this->assistantTelegramFormatting(),
                'reply_markup' => [
                    'inline_keyboard' => $inlineKeyboard,
                ],
            ];
        }

        return [
            ...$this->assistantTelegramFormatting(),
            ...$this->ownerQuickActionFormatting(),
        ];
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    private function assistantInlineKeyboard(AiConversationMessage $message): array
    {
        $pendingActionId = data_get($message->metadata, 'pending_action_id');

        if (filled($pendingActionId)) {
            return [[
                [
                    'text' => __('app.confirm'),
                    'callback_data' => 'tg_action:confirm:'.(int) $pendingActionId,
                ],
                [
                    'text' => __('app.cancel'),
                    'callback_data' => 'tg_action:cancel:'.(int) $pendingActionId,
                ],
            ]];
        }

        $followUps = data_get($message->metadata, 'follow_up_actions', []);

        if (! is_array($followUps)) {
            return [];
        }

        return collect($followUps)
            ->filter(fn (mixed $followUp): bool => is_string($followUp) && trim($followUp) !== '')
            ->take(3)
            ->values()
            ->map(fn (string $followUp, int $index): array => [[
                'text' => $this->telegramButtonText($followUp),
                'callback_data' => 'tg_follow:'.$message->id.':'.$index,
            ]])
            ->all();
    }

    private function telegramButtonText(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > 48 ? mb_substr($text, 0, 45).'...' : $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerQuickActionFormatting(): array
    {
        if (! PlatformAiSetting::ownerAssistantEnabled()) {
            return [];
        }

        return [
            'reply_markup' => [
                'keyboard' => [
                    [
                        ['text' => __('app.telegram_quick_action_create_booking')],
                    ],
                ],
                'resize_keyboard' => true,
                'is_persistent' => true,
            ],
        ];
    }

    private function assistantTelegramText(string $text): string
    {
        $bulletMarker = '__LADNA_TELEGRAM_BULLET__';
        $text = preg_replace('/(^|\R)[ \t]*[*-][ \t]+/u', '$1'.$bulletMarker.' ', $text) ?? $text;
        $parts = preg_split('/(\*\*.+?\*\*)/us', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts)) {
            return str_replace($bulletMarker, '&#8226;', $this->escapeTelegramHtml($text));
        }

        $formatted = collect($parts)
            ->map(function (string $part): string {
                if (str_starts_with($part, '**') && str_ends_with($part, '**') && mb_strlen($part) > 4) {
                    return '<b>'.$this->escapeTelegramHtml(mb_substr($part, 2, -2)).'</b>';
                }

                return $this->escapeTelegramHtml($part);
            })
            ->implode('');

        return str_replace($bulletMarker, '&#8226;', $formatted);
    }

    private function escapeTelegramHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function conversationFor(TelegramChatAuthorization $authorization): AiConversation
    {
        $conversation = AiConversation::firstOrCreate(
            [
                'account_id' => $authorization->account_id,
                'telegram_chat_authorization_id' => $authorization->id,
                'channel' => 'telegram_owner',
                'profile' => $authorization->profile->value,
                'status' => AiConversation::StatusActive,
            ],
            [
                'user_id' => $authorization->user_id,
                'trainer_id' => $authorization->trainer_id,
                'title' => __('app.telegram_owner_bot_title'),
                'last_message_at' => now(),
            ],
        );

        if ($conversation->user_id !== $authorization->user_id || $conversation->trainer_id !== $authorization->trainer_id) {
            $conversation->forceFill([
                'user_id' => $authorization->user_id,
                'trainer_id' => $authorization->trainer_id,
            ])->save();
        }

        return $conversation;
    }
}
