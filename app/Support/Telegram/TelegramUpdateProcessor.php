<?php

namespace App\Support\Telegram;

use App\Enums\AiConversationMessageRole;
use App\Enums\AiProvider;
use App\Enums\StudioAiDisposition;
use App\Enums\StudioPermission;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Enums\TelegramUpdateStatus;
use App\Enums\VoiceRecognitionProvider;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiPendingAction;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Ai\AiConversationImageStore;
use App\Support\Ai\InvalidAiConversationImage;
use App\Support\Ai\StudioAiActionInput;
use App\Support\Ai\StudioAiResult;
use App\Support\Ai\StudioAiUsageFirewall;
use App\Support\Ai\StudioAiUsageLimitExceeded;
use App\Support\Ai\StudioAssistantActionExecutor;
use App\Support\Ai\StudioAssistantActionPlan;
use App\Support\Ai\StudioAssistantActionPlanner;
use App\Support\Ai\Voice\VoiceAudioNormalizer;
use App\Support\Ai\Voice\VoiceTranscriptionException;
use App\Support\Ai\Voice\VoiceTranscriptionService;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TelegramUpdateProcessor
{
    private const MaxImageBytes = AiConversationImageStore::MaxInputBytes;

    /**
     * @var array<int, string>
     */
    private const SupportedImageMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly TelegramContactAuthorizer $contactAuthorizer,
        private readonly TelegramOwnerResponder $ownerResponder,
        private readonly StudioAssistantActionPlanner $actionPlanner,
        private readonly StudioAssistantActionExecutor $actionExecutor,
        private readonly TelegramConversationResetter $conversationResetter,
        private readonly TelegramAssistantTextFormatter $assistantTextFormatter,
        private readonly AiConversationImageStore $conversationImageStore,
        private readonly AccountSubscriptionAccess $subscriptionAccess,
        private readonly VoiceTranscriptionService $voiceTranscriptionService,
        private readonly StudioAiUsageFirewall $usageFirewall,
    ) {}

    public function process(int $telegramUpdateId): void
    {
        $telegramUpdate = TelegramUpdate::with(['account', 'installation.account'])->find($telegramUpdateId);

        if (! $telegramUpdate) {
            return;
        }

        if (($telegramUpdate->account ?? $telegramUpdate->installation?->account)?->isReadOnlyDemo()) {
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

        if ((string) data_get($callbackQuery, 'message.chat.type', 'private') !== 'private') {
            return true;
        }

        $installation = $telegramUpdate->installation;
        $chatId = (string) data_get($callbackQuery, 'message.chat.id');
        $telegramUserId = (string) data_get($callbackQuery, 'from.id');
        $data = (string) data_get($callbackQuery, 'data', '');

        $this->telegramClient->answerCallbackQuery($installation, (string) data_get($callbackQuery, 'id'));

        if (str_starts_with($data, 'tg_select:')) {
            $authorization = $this->contactAuthorizer->authorizeSelection($installation, $callbackQuery);

            if (! $authorization || ! $this->authorizationIsCurrent($authorization)) {
                $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_authorization_failed'));

                return true;
            }

            $telegramUpdate->update(['account_id' => $authorization->account_id]);
            $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_authorized'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization);

            return true;
        }

        $authorization = $this->authorizationForCallback($installation->id, $chatId, $telegramUserId);

        if (! $authorization) {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_authorization_failed'));

            return true;
        }

        $authorization = $this->resolveAuthorizedTrainer($authorization);

        if (! $this->authorizationIsCurrent($authorization)) {
            $this->sendAndStore(
                $telegramUpdate,
                $chatId,
                __('app.telegram_ai_access_expired'),
                [],
                $authorization->account_id,
                $authorization,
            );

            return true;
        }

        $telegramUpdate->update(['account_id' => $authorization->account_id]);

        $statusMessage = $this->startStatusMessage($telegramUpdate, $chatId);
        $typing = $this->startTyping($telegramUpdate, $chatId);

        try {
            if ($data === 'tg_restart') {
                $this->conversationResetter->reset($authorization);
                $this->refreshTyping($typing, force: true);
                $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_conversation_restarted'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization, statusMessage: $statusMessage);

                return true;
            }

            if (preg_match('/^tg_follow:(\d+):(\d+)$/', $data, $matches) === 1) {
                return $this->processFollowUpCallback($telegramUpdate, $authorization, $chatId, (int) $matches[1], (int) $matches[2], $callbackQuery, $typing, $statusMessage);
            }

            if ($data === 'tg_booking:cancel') {
                return $this->processBookingCancelCallback($telegramUpdate, $authorization, $chatId, $callbackQuery, $typing, $statusMessage);
            }

            if (preg_match('/^tg_action:(confirm|cancel):(\d+)$/', $data, $matches) === 1) {
                return $this->processActionCallback($telegramUpdate, $authorization, $chatId, $matches[1], (int) $matches[2], $typing, $statusMessage);
            }

            $this->refreshTyping($typing, force: true);
            $this->sendAndStore($telegramUpdate, $chatId, __('app.assistant_action_unknown'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization, statusMessage: $statusMessage);

            return true;
        } finally {
            $this->stopTyping($typing);
        }
    }

    private function processMessage(TelegramUpdate $telegramUpdate): bool
    {
        $message = data_get($telegramUpdate->payload, 'message');

        if (! is_array($message)) {
            $telegramUpdate->update(['status' => TelegramUpdateStatus::Ignored->value, 'processed_at' => now()]);

            return true;
        }

        if ((string) data_get($message, 'chat.type', 'private') !== 'private') {
            return true;
        }

        $installation = $telegramUpdate->installation;
        $chatId = (string) data_get($message, 'chat.id');
        $text = trim((string) (data_get($message, 'text') ?? data_get($message, 'caption', '')));

        $inboundMessage = TelegramMessage::create([
            'account_id' => $telegramUpdate->account_id,
            'telegram_bot_installation_id' => $installation->id,
            'telegram_update_id' => $telegramUpdate->id,
            'profile' => $installation->profile->value,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => (string) data_get($message, 'message_id'),
            'telegram_user_id' => (string) data_get($message, 'from.id'),
            'direction' => 'inbound',
            'message_type' => $this->messageType($message),
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
                ? __('app.telegram_unknown_phone_signup', ['url' => route('demo.login')])
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
            if ($this->shouldReplyForMediaGroup($inboundMessage)) {
                $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_share_contact_to_authorize'), [
                    'reply_markup' => [
                        'keyboard' => [[
                            ['text' => __('app.telegram_share_phone_button'), 'request_contact' => true],
                        ]],
                        'resize_keyboard' => true,
                        'one_time_keyboard' => true,
                    ],
                ]);
            }

            return true;
        }

        $authorization = $this->resolveAuthorizedTrainer($authorization);

        if (! $this->authorizationIsCurrent($authorization)) {
            $this->sendAndStore(
                $telegramUpdate,
                $chatId,
                __('app.telegram_ai_access_expired'),
                [],
                $authorization->account_id,
                $authorization,
            );

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

        if ($this->hasVoice($message)) {
            return $this->processAuthorizedOwnerVoice(
                $telegramUpdate,
                $authorization,
                $inboundMessage,
                $chatId,
                $message,
            );
        }

        if ($this->hasImage($message) && $this->isSupportedTelegramCommand($text)) {
            $processed = $this->processAuthorizedOwnerText($telegramUpdate, $authorization, $inboundMessage, $chatId, $text);

            if ($this->shouldReplyForMediaGroup($inboundMessage)) {
                $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_image_command_ignored'), [], $authorization->account_id, $authorization);
            }

            return $processed;
        }

        if ($this->hasImage($message) && ! PlatformAiSetting::imageInferenceEnabled()) {
            if ($this->shouldReplyForMediaGroup($inboundMessage)) {
                $this->sendAndStore($telegramUpdate, $chatId, __('app.assistant_image_provider_unsupported'), [], $authorization->account_id, $authorization);
            }

            return true;
        }

        if ($this->hasMediaGroup($message)) {
            if ($this->shouldReplyForMediaGroup($inboundMessage)) {
                $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_image_album_unsupported'), [], $authorization->account_id, $authorization);
            }

            return true;
        }

        if (! $this->hasImage($message) && data_get($message, 'document')) {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_image_unsupported'), [], $authorization->account_id, $authorization);

            return true;
        }

        if (! $this->hasImage($message)) {
            return $this->processAuthorizedOwnerText($telegramUpdate, $authorization, $inboundMessage, $chatId, $text);
        }

        $image = $this->downloadImage($telegramUpdate, $message);

        if (is_string($image)) {
            $this->sendAndStore($telegramUpdate, $chatId, __('app.'.$image), [], $authorization->account_id, $authorization);

            return true;
        }

        return $this->processAuthorizedOwnerText(
            $telegramUpdate,
            $authorization,
            $inboundMessage,
            $chatId,
            $text,
            imageContents: $image['contents'],
            imageOriginalName: $image['original_name'],
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function processAuthorizedOwnerVoice(
        TelegramUpdate $telegramUpdate,
        TelegramChatAuthorization $authorization,
        TelegramMessage $inboundMessage,
        string $chatId,
        array $message,
    ): bool {
        $setting = PlatformAiSetting::current();
        $unavailableMessage = $this->voiceAvailabilityMessage($setting);

        if ($unavailableMessage !== null) {
            $this->sendAndStore(
                $telegramUpdate,
                $chatId,
                $unavailableMessage,
                [],
                $authorization->account_id,
                $authorization,
            );

            return true;
        }

        $candidate = $this->voiceCandidate($message);

        if (is_string($candidate)) {
            $this->sendAndStore(
                $telegramUpdate,
                $chatId,
                __('app.'.$candidate),
                [],
                $authorization->account_id,
                $authorization,
            );

            return true;
        }

        $statusMessage = $this->startStatusMessage($telegramUpdate, $chatId);
        $typing = $this->startTyping($telegramUpdate, $chatId);

        try {
            $this->updateStatus($statusMessage, 'assistant_status_transcribing_voice');
            $this->refreshTyping($typing, force: true);
            $download = $this->downloadVoice($telegramUpdate, $candidate);

            if (is_string($download)) {
                $this->sendAndStore(
                    $telegramUpdate,
                    $chatId,
                    __('app.'.$download),
                    [],
                    $authorization->account_id,
                    $authorization,
                    statusMessage: $statusMessage,
                );

                return true;
            }

            $conversation = $this->conversationFor($authorization);
            $text = $this->voiceTranscriptionService->transcribe(
                $download['contents'],
                $authorization->account,
                $authorization->user,
                'telegram_owner',
                $setting,
                $conversation,
            );
            $inboundMessage->update(['text' => $text]);

            return $this->processAuthorizedOwnerText(
                $telegramUpdate,
                $authorization,
                $inboundMessage,
                $chatId,
                $text,
                $typing,
                $statusMessage,
            );
        } catch (VoiceTranscriptionException $exception) {
            $this->sendAndStore(
                $telegramUpdate,
                $chatId,
                __('app.'.$this->voiceErrorTranslationKey($exception)),
                [],
                $authorization->account_id,
                $authorization,
                statusMessage: $statusMessage,
            );

            return true;
        } catch (StudioAiUsageLimitExceeded $exception) {
            $messageText = $this->usageFirewall
                ->resultForDecision($exception->decision, $authorization->account)
                ->text;
            $this->sendAndStore(
                $telegramUpdate,
                $chatId,
                $messageText,
                [],
                $authorization->account_id,
                $authorization,
                statusMessage: $statusMessage,
            );

            return true;
        } finally {
            $this->stopTyping($typing);
        }
    }

    private function processAuthorizedOwnerText(TelegramUpdate $telegramUpdate, TelegramChatAuthorization $authorization, TelegramMessage $inboundMessage, string $chatId, string $text, ?TelegramTypingIndicator $typing = null, ?TelegramStatusMessage $statusMessage = null, ?string $imageContents = null, ?string $imageOriginalName = null): bool
    {
        $account = $authorization->account;
        $statusMessage ??= $this->startStatusMessage($telegramUpdate, $chatId);
        $typing ??= $this->startTyping($telegramUpdate, $chatId);

        try {
            if ($this->isRestartShortcut($text)) {
                $this->conversationResetter->reset($authorization);
                $this->refreshTyping($typing, force: true);
                $this->sendAndStore($telegramUpdate, $chatId, __('app.telegram_conversation_restarted'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization, statusMessage: $statusMessage);

                return true;
            }

            $conversation = $this->conversationFor($authorization);
            $currentMessage = $conversation->messages()->create([
                'account_id' => $authorization->account_id,
                'telegram_message_id' => $inboundMessage->id,
                'role' => AiConversationMessageRole::User->value,
                'content' => $text,
                'occurred_at' => now(),
            ]);

            if ($imageContents !== null) {
                try {
                    $this->conversationImageStore->storeTelegramImage(
                        $currentMessage,
                        $imageContents,
                        $imageOriginalName,
                    );
                } catch (InvalidAiConversationImage $exception) {
                    $currentMessage->delete();
                    $this->refreshTyping($typing, force: true);
                    $this->sendAndStore(
                        $telegramUpdate,
                        $chatId,
                        __('app.'.$this->invalidImageTranslationKey($exception)),
                        [],
                        $authorization->account_id,
                        $authorization,
                        statusMessage: $statusMessage,
                    );

                    return true;
                }
            }

            $this->updateStatus($statusMessage, 'assistant_status_checking_database');
            $this->refreshTyping($typing, force: true);

            $plan = $this->exactActionPlan($account, $authorization, $conversation, $text);
            $aiResult = null;

            if (! $plan) {
                $aiResult = $this->ownerResponder->respond(
                    $account,
                    $text,
                    $authorization,
                    $conversation,
                    $currentMessage,
                    function (string $statusKey) use ($typing, $statusMessage): ?Response {
                        $response = $this->updateStatus($statusMessage, $statusKey);
                        $this->refreshTyping($typing, force: true);

                        return $response;
                    },
                );

                if ($imageContents !== null && $text === '' && $aiResult->isAction()) {
                    $aiResult = StudioAiResult::fallback('image_only_action_not_allowed');
                } elseif ($aiResult->isAction() && $authorization->user) {
                    $plan = $this->actionPlanner->plan(
                        $account,
                        $authorization->user,
                        $authorization->trainer,
                        $conversation,
                        $aiResult->disposition,
                        $aiResult->actionInput,
                    );
                }
            }

            if ($plan?->handled) {
                $this->updateStatus($statusMessage, $plan->pendingAction ? 'assistant_status_preparing_action' : 'assistant_status_checking_database');
                $this->refreshTyping($typing, force: true);

                $result = [
                    'response' => $plan->message ?? __('app.assistant_pending_action_created'),
                    'rejected' => false,
                    'used_ai' => $aiResult?->usedAi ?? false,
                    'metadata' => [
                        'used_ai' => $aiResult?->usedAi ?? false,
                        'provider' => $aiResult?->provider,
                        'model' => $aiResult?->model,
                        'disposition' => $aiResult?->disposition->value,
                        'calendar_reference' => $aiResult?->calendarReference?->toArray(),
                        ...($plan->pendingAction ? ['pending_action_id' => $plan->pendingAction->id] : []),
                        ...$plan->metadata,
                    ],
                ];
            } else {
                if ($aiResult?->isAction()) {
                    $aiResult = StudioAiResult::fallback('invalid_ai_action');
                }

                $aiResult ??= StudioAiResult::fallback('invalid_ai_response');
                $result['metadata'] = [
                    'used_ai' => $aiResult->usedAi,
                    'provider' => $aiResult->provider,
                    'model' => $aiResult->model,
                    'fallback_reason' => $aiResult->fallbackReason,
                    'limit_scope' => $aiResult->limitScope,
                    'retry_after_seconds' => $aiResult->retryAfterSeconds,
                    'blocked_until' => $aiResult->blockedUntil?->toIso8601String(),
                    'follow_up_actions' => $aiResult->followUpActions,
                    'help_sources' => $aiResult->helpSources,
                    'disposition' => $aiResult->disposition->value,
                    'calendar_reference' => $aiResult->calendarReference?->toArray(),
                ];
                $result['response'] = $aiResult->text !== '' ? $aiResult->text : __('app.assistant_ai_unavailable');
                $result['rejected'] = $aiResult->rejected;
                $result['used_ai'] = $aiResult->usedAi;
            }

            $assistantMessage = $conversation->messages()->create([
                'account_id' => $authorization->account_id,
                'role' => $result['rejected'] ? AiConversationMessageRole::RejectedIntent->value : AiConversationMessageRole::Assistant->value,
                'content' => $result['response'],
                'metadata' => $result['metadata'],
                'occurred_at' => now(),
            ]);

            $this->refreshTyping($typing, force: true);
            $this->stopTyping($typing);

            $outboundMessage = $this->sendAndStore(
                $telegramUpdate,
                $chatId,
                $result['response'],
                $this->assistantTelegramReplyMarkup($assistantMessage),
                $account->id,
                $authorization,
                $this->assistantTextFormatter->format($result['response']),
                $statusMessage,
            );

            $assistantMessage->update(['telegram_message_id' => $outboundMessage->id]);

            $conversation->update(['last_message_at' => now()]);

            return true;
        } finally {
            $this->stopTyping($typing);
        }
    }

    private function voiceAvailabilityMessage(PlatformAiSetting $setting): ?string
    {
        if (! $setting->owner_voice_input_enabled) {
            return __('app.telegram_voice_disabled');
        }

        if ($setting->owner_voice_recognition_provider !== VoiceRecognitionProvider::OpenAi) {
            return __('app.telegram_voice_provider_unavailable');
        }

        $apiKey = PlatformAiProviderCredential::query()
            ->where('provider', AiProvider::OpenAiApiKey->value)
            ->first()
            ?->apiKey();

        return filled($apiKey) ? null : __('app.telegram_voice_openai_key_missing');
    }

    /**
     * @param  array{file_id: string}  $candidate
     * @return array{contents: string}|string
     */
    private function downloadVoice(TelegramUpdate $telegramUpdate, array $candidate): array|string
    {
        $fileResponse = $this->telegramClient->getFile(
            $telegramUpdate->installation,
            $candidate['file_id'],
        );

        if (! $fileResponse?->successful()) {
            return 'telegram_voice_download_failed';
        }

        $filePath = (string) data_get($fileResponse->json(), 'result.file_path', '');
        $fileSize = (int) data_get($fileResponse->json(), 'result.file_size', 0);

        if ($fileSize > VoiceAudioNormalizer::MaxBytes) {
            return 'assistant_voice_too_large';
        }

        $download = $this->telegramClient->downloadFile(
            $telegramUpdate->installation,
            $filePath,
            VoiceAudioNormalizer::MaxBytes,
        );

        if ($download['too_large']) {
            return 'assistant_voice_too_large';
        }

        $downloadResponse = $download['response'];

        if (! $downloadResponse?->successful()) {
            return 'telegram_voice_download_failed';
        }

        $contentLength = (int) $downloadResponse->header('Content-Length');
        $contents = $downloadResponse->body();

        if ($contentLength > VoiceAudioNormalizer::MaxBytes || strlen($contents) > VoiceAudioNormalizer::MaxBytes) {
            return 'assistant_voice_too_large';
        }

        return $contents !== '' ? ['contents' => $contents] : 'assistant_voice_invalid';
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{file_id: string}|string
     */
    private function voiceCandidate(array $message): array|string
    {
        $voice = data_get($message, 'voice');

        if (! is_array($voice)) {
            return 'assistant_voice_invalid';
        }

        if ((int) data_get($voice, 'duration', 0) > VoiceAudioNormalizer::MaxDurationSeconds) {
            return 'assistant_voice_too_long';
        }

        if ((int) data_get($voice, 'file_size', 0) > VoiceAudioNormalizer::MaxBytes) {
            return 'assistant_voice_too_large';
        }

        $fileId = (string) data_get($voice, 'file_id', '');

        return $fileId !== '' ? ['file_id' => $fileId] : 'assistant_voice_invalid';
    }

    private function voiceErrorTranslationKey(VoiceTranscriptionException $exception): string
    {
        return match ($exception->reason()) {
            'busy' => 'assistant_voice_busy',
            'empty_audio', 'invalid_audio' => 'assistant_voice_invalid',
            'audio_too_large' => 'assistant_voice_too_large',
            'audio_too_long' => 'assistant_voice_too_long',
            'provider_unavailable' => 'assistant_voice_provider_unavailable',
            'missing_openai_api_key' => 'assistant_voice_openai_key_missing',
            'empty_transcript' => 'assistant_voice_empty_transcript',
            'transcript_too_long' => 'assistant_voice_transcript_too_long',
            default => 'assistant_voice_transcription_failed',
        };
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{contents: string, original_name: string}|string
     */
    private function downloadImage(TelegramUpdate $telegramUpdate, array $message): array|string
    {
        $candidate = $this->imageCandidate($message);

        if (is_string($candidate)) {
            return $candidate;
        }

        $fileResponse = $this->telegramClient->getFile(
            $telegramUpdate->installation,
            $candidate['file_id'],
        );

        if (! $fileResponse?->successful()) {
            return 'telegram_image_download_failed';
        }

        $filePath = (string) data_get($fileResponse->json(), 'result.file_path', '');
        $fileSize = (int) data_get($fileResponse->json(), 'result.file_size', 0);

        if ($fileSize > self::MaxImageBytes) {
            return 'telegram_image_too_large';
        }

        $download = $this->telegramClient->downloadFile(
            $telegramUpdate->installation,
            $filePath,
            self::MaxImageBytes,
        );

        if ($download['too_large']) {
            return 'telegram_image_too_large';
        }

        $downloadResponse = $download['response'];

        if (! $downloadResponse?->successful()) {
            return 'telegram_image_download_failed';
        }

        $contentLength = (int) $downloadResponse->header('Content-Length');
        $contents = $downloadResponse->body();

        if ($contentLength > self::MaxImageBytes || strlen($contents) > self::MaxImageBytes) {
            return 'telegram_image_too_large';
        }

        if ($contents === '') {
            return 'telegram_image_invalid';
        }

        return [
            'contents' => $contents,
            'original_name' => $candidate['original_name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{file_id: string, original_name: string}|string
     */
    private function imageCandidate(array $message): array|string
    {
        $photos = data_get($message, 'photo');

        if (is_array($photos) && $photos !== []) {
            $eligiblePhotos = collect($photos)
                ->filter(fn (mixed $photo): bool => is_array($photo)
                    && filled(data_get($photo, 'file_id'))
                    && ((int) data_get($photo, 'file_size', 0) === 0
                        || (int) data_get($photo, 'file_size') <= self::MaxImageBytes))
                ->sortByDesc(fn (array $photo): int => (int) data_get($photo, 'width', 0) * (int) data_get($photo, 'height', 0));

            $photo = $eligiblePhotos->first();

            if (! is_array($photo)) {
                return 'telegram_image_too_large';
            }

            return [
                'file_id' => (string) data_get($photo, 'file_id'),
                'original_name' => 'telegram-photo.jpg',
            ];
        }

        $document = data_get($message, 'document');

        if (! is_array($document) || ! $this->isSupportedImageDocument($document)) {
            return 'telegram_image_unsupported';
        }

        if ((int) data_get($document, 'file_size', 0) > self::MaxImageBytes) {
            return 'telegram_image_too_large';
        }

        $fileId = (string) data_get($document, 'file_id', '');

        if ($fileId === '') {
            return 'telegram_image_invalid';
        }

        $originalName = basename(str_replace(
            '\\',
            '/',
            (string) data_get($document, 'file_name', 'telegram-image'),
        ));

        return [
            'file_id' => $fileId,
            'original_name' => $originalName !== '' ? $originalName : 'telegram-image',
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function messageType(array $message): string
    {
        if (data_get($message, 'contact')) {
            return 'contact';
        }

        if ($this->hasVoice($message)) {
            return 'voice';
        }

        if (data_get($message, 'photo')) {
            return 'photo';
        }

        if (data_get($message, 'document')) {
            return $this->hasImage($message) ? 'image_document' : 'document';
        }

        return 'text';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function hasVoice(array $message): bool
    {
        return is_array(data_get($message, 'voice'));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function hasImage(array $message): bool
    {
        if (is_array(data_get($message, 'photo')) && data_get($message, 'photo') !== []) {
            return true;
        }

        $document = data_get($message, 'document');

        return is_array($document) && $this->isSupportedImageDocument($document);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function isSupportedImageDocument(array $document): bool
    {
        $mimeType = Str::lower((string) data_get($document, 'mime_type', ''));

        if (in_array($mimeType, self::SupportedImageMimeTypes, true)) {
            return true;
        }

        $extension = Str::lower(pathinfo((string) data_get($document, 'file_name', ''), PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function hasMediaGroup(array $message): bool
    {
        return filled(data_get($message, 'media_group_id'));
    }

    private function shouldReplyForMediaGroup(TelegramMessage $message): bool
    {
        $mediaGroupId = (string) data_get($message->payload, 'media_group_id', '');

        if ($mediaGroupId === '') {
            return true;
        }

        $key = 'telegram:media-group-reply:'.hash('sha256', implode(':', [
            $message->telegram_bot_installation_id,
            $message->telegram_chat_id,
            $mediaGroupId,
        ]));

        return Cache::add($key, true, now()->addMinutes(10));
    }

    private function isSupportedTelegramCommand(string $text): bool
    {
        return preg_match(
            '/^\/(?:start|restart|help|book|cancel_booking|cancel)(?:@\w+)?$/iu',
            trim($text),
        ) === 1;
    }

    private function invalidImageTranslationKey(InvalidAiConversationImage $exception): string
    {
        $reason = Str::lower($exception->reason());

        if (Str::contains($reason, ['large', 'size', 'pixel', 'dimension'])) {
            return 'telegram_image_too_large';
        }

        if (Str::contains($reason, ['mime', 'type', 'format', 'unsupported'])) {
            return 'telegram_image_unsupported';
        }

        return 'telegram_image_invalid';
    }

    private function typingIndicator(TelegramUpdate $telegramUpdate, string $chatId): TelegramTypingIndicator
    {
        return new TelegramTypingIndicator(
            $this->telegramClient,
            $telegramUpdate->installation,
            $chatId,
            $this->typingRefreshSeconds(),
            $this->typingMaxSeconds(),
        );
    }

    private function startTyping(TelegramUpdate $telegramUpdate, string $chatId): ?TelegramTypingIndicator
    {
        if ($chatId === '') {
            return null;
        }

        $typing = $this->typingIndicator($telegramUpdate, $chatId);
        $typing->start();

        return $typing;
    }

    private function refreshTyping(?TelegramTypingIndicator $typing, bool $force = false): ?Response
    {
        return $typing?->refresh($force);
    }

    private function stopTyping(?TelegramTypingIndicator $typing): void
    {
        $typing?->stop();
    }

    private function startStatusMessage(TelegramUpdate $telegramUpdate, string $chatId): ?TelegramStatusMessage
    {
        if ($chatId === '') {
            return null;
        }

        $statusMessage = new TelegramStatusMessage(
            $this->telegramClient,
            $telegramUpdate->installation,
            $chatId,
            __('app.assistant_status_thinking'),
        );
        $statusMessage->start();

        return $statusMessage;
    }

    private function updateStatus(?TelegramStatusMessage $statusMessage, string $statusKey): ?Response
    {
        return $statusMessage?->update(__('app.'.$statusKey));
    }

    private function typingRefreshSeconds(): float
    {
        return max(0.0, (float) config('services.telegram.typing_refresh_seconds', 2.0));
    }

    private function typingMaxSeconds(): int
    {
        return max(1, (int) config('services.telegram.typing_max_seconds', 120));
    }

    private function processFollowUpCallback(TelegramUpdate $telegramUpdate, TelegramChatAuthorization $authorization, string $chatId, int $messageId, int $index, array $callbackQuery, ?TelegramTypingIndicator $typing = null, ?TelegramStatusMessage $statusMessage = null): bool
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
            $this->refreshTyping($typing, force: true);
            $this->sendAndStore($telegramUpdate, $chatId, __('app.assistant_action_unknown'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization, statusMessage: $statusMessage);

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

        return $this->processAuthorizedOwnerText($telegramUpdate, $authorization, $inboundMessage, $chatId, $text, $typing, $statusMessage);
    }

    private function processBookingCancelCallback(TelegramUpdate $telegramUpdate, TelegramChatAuthorization $authorization, string $chatId, array $callbackQuery, ?TelegramTypingIndicator $typing = null, ?TelegramStatusMessage $statusMessage = null): bool
    {
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
            'text' => '/cancel_booking',
            'payload' => $callbackQuery,
            'sent_at' => now(),
        ]);

        return $this->processAuthorizedOwnerText($telegramUpdate, $authorization, $inboundMessage, $chatId, '/cancel_booking', $typing, $statusMessage);
    }

    private function processActionCallback(TelegramUpdate $telegramUpdate, TelegramChatAuthorization $authorization, string $chatId, string $mode, int $actionId, ?TelegramTypingIndicator $typing = null, ?TelegramStatusMessage $statusMessage = null): bool
    {
        $this->updateStatus($statusMessage, 'assistant_status_checking_database');
        $this->refreshTyping($typing, force: true);

        $action = AiPendingAction::query()
            ->whereKey($actionId)
            ->where('account_id', $authorization->account_id)
            ->whereHas('conversation', fn ($query) => $query
                ->where('telegram_chat_authorization_id', $authorization->id)
                ->where('channel', 'telegram_owner'))
            ->first();

        if (! $action || ! $action->isPending()) {
            $this->refreshTyping($typing, force: true);
            $this->sendAndStore($telegramUpdate, $chatId, __('app.assistant_action_not_pending'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization, statusMessage: $statusMessage);

            return true;
        }

        if ($mode === 'cancel') {
            $action->update([
                'status' => AiPendingAction::StatusCancelled,
                'cancelled_at' => now(),
            ]);

            $this->updateStatus($statusMessage, 'assistant_status_preparing_action');
            $this->refreshTyping($typing, force: true);
            $this->sendActionResult($telegramUpdate, $authorization, $chatId, $action, __('app.assistant_action_cancelled'), [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
            ], $statusMessage);

            return true;
        }

        if (! $authorization->user) {
            $this->refreshTyping($typing, force: true);
            $this->sendAndStore($telegramUpdate, $chatId, __('app.assistant_action_forbidden'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization, statusMessage: $statusMessage);

            return true;
        }

        try {
            $this->updateStatus($statusMessage, 'assistant_status_executing_action');
            $this->refreshTyping($typing, force: true);
            $result = $this->actionExecutor->execute($action, $authorization->user);
            $message = (string) ($result['message'] ?? __('app.assistant_action_executed'));
            $this->refreshTyping($typing, force: true);
            $this->sendActionResult($telegramUpdate, $authorization, $chatId, $action->refresh(), $message, [
                'action_id' => $action->id,
                'action_name' => $action->action_name,
                'result' => $result,
            ], $statusMessage);
        } catch (AuthorizationException $exception) {
            $this->refreshTyping($typing, force: true);
            $this->sendAndStore($telegramUpdate, $chatId, $exception->getMessage() ?: __('app.assistant_action_forbidden'), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization, statusMessage: $statusMessage);
        } catch (ValidationException $exception) {
            $this->refreshTyping($typing, force: true);
            $this->sendAndStore($telegramUpdate, $chatId, $this->validationMessage($exception), $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization, statusMessage: $statusMessage);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function sendActionResult(TelegramUpdate $telegramUpdate, TelegramChatAuthorization $authorization, string $chatId, AiPendingAction $action, string $message, array $metadata, ?TelegramStatusMessage $statusMessage = null): void
    {
        $outboundMessage = $this->sendAndStore($telegramUpdate, $chatId, $message, $this->ownerQuickActionFormatting(), $authorization->account_id, $authorization, statusMessage: $statusMessage);

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

    private function authorizationForCallback(int $installationId, string $chatId, string $telegramUserId): ?TelegramChatAuthorization
    {
        return TelegramChatAuthorization::query()
            ->with(['account', 'user', 'trainer'])
            ->where('telegram_bot_installation_id', $installationId)
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_user_id', $telegramUserId)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->first();
    }

    private function exactActionPlan(Account $account, TelegramChatAuthorization $authorization, AiConversation $conversation, string $text): ?StudioAssistantActionPlan
    {
        $normalized = Str::of($text)->lower()->squish()->toString();

        if (preg_match('/^\/help(?:@\w+)?$/u', $normalized) === 1) {
            return StudioAssistantActionPlan::message(__('app.telegram_owner_help'));
        }

        if (! $authorization->user) {
            return null;
        }

        if ($this->isCreateBookingShortcut($text)) {
            return $this->actionPlanner->startGroupBookingDialog($account, $authorization->user, $authorization->trainer, $conversation);
        }

        if (preg_match('/^\/(?:cancel_booking|cancel)(?:@\w+)?$/u', $normalized) === 1) {
            return $this->actionPlanner->plan(
                $account,
                $authorization->user,
                $authorization->trainer,
                $conversation,
                StudioAiDisposition::CancelDialog,
                new StudioAiActionInput,
            );
        }

        return $this->actionPlanner->planExactDialogOption(
            $account,
            $authorization->user,
            $authorization->trainer,
            $conversation,
            $text,
        );
    }

    private function isCreateBookingShortcut(string $text): bool
    {
        $normalized = Str::of($text)->lower()->squish()->toString();

        return $normalized === Str::of(__('app.telegram_quick_action_create_booking'))->lower()->squish()->toString()
            || preg_match('/^\/book(?:@\w+)?$/u', $normalized) === 1;
    }

    private function isRestartShortcut(string $text): bool
    {
        $normalized = Str::of($text)->lower()->squish()->toString();

        return preg_match('/^\/(?:start|restart)(?:@\w+)?$/u', $normalized) === 1;
    }

    private function resolveAuthorizedTrainer(TelegramChatAuthorization $authorization): TelegramChatAuthorization
    {
        if ($authorization->trainer_id || ! $authorization->user_id) {
            return $authorization;
        }

        $trainer = Trainer::query()
            ->where('account_id', $authorization->account_id)
            ->where('is_active', true)
            ->where(function ($query) use ($authorization): void {
                $query->where('user_id', $authorization->user_id);

                if (filled($authorization->phone)) {
                    $query->orWhere('phone', $authorization->phone);
                }
            })
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$authorization->user_id])
            ->first();

        if (! $trainer) {
            return $authorization;
        }

        $authorization->forceFill(['trainer_id' => $trainer->id])->save();
        $authorization->setRelation('trainer', $trainer);

        return $authorization;
    }

    private function authorizationIsCurrent(TelegramChatAuthorization $authorization): bool
    {
        $account = Account::query()
            ->active()
            ->operational()
            ->find($authorization->account_id);
        $user = User::query()->find($authorization->user_id);

        if (! $account || ! $user) {
            return false;
        }

        if (! $user->isPlatformAdmin() && ! $this->subscriptionAccess->canEditStudio($account)) {
            return false;
        }

        if (! $account->userCan($user, StudioPermission::InteractWithTelegramBot)) {
            return false;
        }

        $authorization->setRelation('account', $account);
        $authorization->setRelation('user', $user);

        return true;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function sendAndStore(TelegramUpdate $telegramUpdate, string $chatId, string $text, array $extra = [], ?int $accountId = null, ?TelegramChatAuthorization $authorization = null, ?string $telegramText = null, ?TelegramStatusMessage $statusMessage = null): TelegramMessage
    {
        $sentExtra = $statusMessage ? $this->editableMessageExtra($extra) : $extra;
        $response = $statusMessage
            ? $statusMessage->finalize($telegramText ?? $text, $sentExtra)
            : $this->telegramClient->sendMessage($telegramUpdate->installation, $chatId, $telegramText ?? $text, $sentExtra);

        return TelegramMessage::create([
            'account_id' => $accountId ?? $telegramUpdate->account_id,
            'telegram_bot_installation_id' => $telegramUpdate->telegram_bot_installation_id,
            'telegram_chat_authorization_id' => $authorization?->id,
            'telegram_update_id' => $telegramUpdate->id,
            'profile' => $telegramUpdate->profile->value,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => $this->telegramMessageId($response),
            'direction' => 'outbound',
            'message_type' => 'text',
            'text' => $text,
            'payload' => $sentExtra ?: null,
            'sent_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function editableMessageExtra(array $extra): array
    {
        if (data_get($extra, 'reply_markup.inline_keyboard')) {
            return $extra;
        }

        unset($extra['reply_markup']);

        return $extra;
    }

    private function telegramMessageId(?Response $response): ?string
    {
        $messageId = data_get($response?->json(), 'result.message_id');

        return filled($messageId) ? (string) $messageId : null;
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

        $keyboard = [];
        $followUps = data_get($message->metadata, 'follow_up_actions', []);

        if (is_array($followUps)) {
            $keyboard = collect($followUps)
                ->filter(fn (mixed $followUp): bool => is_string($followUp) && trim($followUp) !== '')
                ->take(3)
                ->values()
                ->map(fn (string $followUp, int $index): array => [[
                    'text' => $this->telegramButtonText($followUp),
                    'callback_data' => 'tg_follow:'.$message->id.':'.$index,
                ]])
                ->all();
        }

        if ($this->hasActiveBookingDialog($message)) {
            $keyboard[] = [[
                'text' => __('app.assistant_booking_dialog_cancel_button'),
                'callback_data' => 'tg_booking:cancel',
            ]];
        }

        return $keyboard;
    }

    private function hasActiveBookingDialog(AiConversationMessage $message): bool
    {
        $status = (string) data_get($message->metadata, 'booking_dialog.status', '');

        return in_array($status, ['awaiting_customer', 'awaiting_trainer', 'awaiting_date', 'awaiting_class'], true);
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
        return [
            'reply_markup' => [
                'remove_keyboard' => true,
            ],
        ];
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
