<?php

namespace App\Support\Ai;

use App\Enums\AiProvider;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\TelegramChatAuthorization;
use Throwable;

class StudioAiInference
{
    public function __construct(
        private readonly StudioAiGuard $guard,
        private readonly StudioAiContextBuilder $contextBuilder,
        private readonly OllamaCloudClient $ollamaCloudClient,
    ) {}

    public function respond(Account $account, string $text, ?TelegramChatAuthorization $authorization = null, ?AiConversation $conversation = null): StudioAiResult
    {
        $setting = PlatformAiSetting::current();

        if (! $setting->owner_ai_assistant_enabled || ! $setting->active_provider || ! $setting->active_model) {
            return StudioAiResult::fallback('ai_not_configured');
        }

        if ($setting->active_provider !== AiProvider::OllamaCloud) {
            return StudioAiResult::fallback('provider_not_implemented');
        }

        $credential = PlatformAiProviderCredential::query()
            ->where('provider', AiProvider::OllamaCloud->value)
            ->first();

        $apiKey = $credential?->apiKey();

        if (! $apiKey) {
            return StudioAiResult::fallback('missing_ollama_api_key');
        }

        try {
            if (! $this->guard->isStudioScoped($account, $text, $apiKey, $setting->active_model)) {
                return StudioAiResult::rejected(__('app.telegram_out_of_scope'));
            }
        } catch (Throwable $throwable) {
            report($throwable);

            return StudioAiResult::fallback('scope_classifier_failed');
        }

        try {
            $response = $this->ollamaCloudClient->chat(
                $apiKey,
                $setting->active_model,
                $this->messages($account, $text, $authorization, $conversation, $setting),
            );

            return StudioAiResult::ai($response['content'], AiProvider::OllamaCloud->value, $setting->active_model);
        } catch (Throwable $throwable) {
            report($throwable);

            return StudioAiResult::fallback('provider_request_failed');
        }
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function messages(Account $account, string $text, ?TelegramChatAuthorization $authorization, ?AiConversation $conversation, PlatformAiSetting $setting): array
    {
        $displayName = $setting->bot_display_name ?: 'Ladna assistant';
        $context = $this->contextBuilder->studioContext($account);
        $platformInstructions = trim((string) $setting->internal_instructions);
        $system = implode("\n", array_filter([
            "You are {$displayName}, an assistant for one Ladna studio account.",
            'Answer only studio operations questions for the provided studio context.',
            'Do not answer recipes, politics, general knowledge, homework, or non-studio requests.',
            'Use only the provided context and chat history. If the needed studio data is missing, say that it is not available in Ladna.',
            'Never execute booking changes directly. Mutating actions require a server-side pending action and explicit user confirmation.',
            'Keep answers concise and practical.',
            $platformInstructions !== '' ? 'Internal product-owner instruction: '.$platformInstructions : null,
        ]));

        return [
            ['role' => 'system', 'content' => $system],
            ...($conversation
                ? $this->contextBuilder->recentConversationMessages($conversation)
                : $this->contextBuilder->recentMessages($authorization)),
            [
                'role' => 'user',
                'content' => "Studio context JSON:\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\nOwner request:\n".$text,
            ],
        ];
    }
}
