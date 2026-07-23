<?php

namespace App\Support\Telegram;

use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\TelegramChatAuthorization;
use App\Support\Ai\StudioAiInference;
use App\Support\Ai\StudioAiResult;

class TelegramOwnerResponder
{
    public function __construct(private readonly StudioAiInference $studioAiInference) {}

    /**
     * @param  callable(string): mixed|null  $beforeProviderRequest
     */
    public function respond(
        Account $account,
        string $text,
        TelegramChatAuthorization $authorization,
        AiConversation $conversation,
        AiConversationMessage $currentMessage,
        ?callable $beforeProviderRequest = null,
    ): StudioAiResult {
        return $this->studioAiInference->respond(
            $account,
            $text,
            conversation: $conversation,
            currentMessage: $currentMessage,
            actorUser: $authorization->user,
            actorTrainer: $authorization->trainer,
            beforeProviderRequest: $beforeProviderRequest,
        );
    }
}
