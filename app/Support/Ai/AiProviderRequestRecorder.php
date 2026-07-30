<?php

namespace App\Support\Ai;

use App\Enums\AiProvider;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiProviderRequest;
use App\Models\PlatformAiSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

class AiProviderRequestRecorder
{
    public function __construct(private readonly StudioAiUsageFirewall $firewall) {}

    /**
     * @param  callable(): array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function record(
        Account $account,
        ?User $user,
        ?AiConversation $conversation,
        ?AiConversationMessage $message,
        string $channel,
        AiProvider $provider,
        string $model,
        string $requestType,
        ?int $providerRound,
        callable $request,
        PlatformAiSetting $setting,
    ): array {
        if ($user) {
            $decision = $this->firewall->reserveProviderCall($account, $user, $setting);

            if (! $decision->allowed) {
                throw new StudioAiUsageLimitExceeded($decision);
            }
        }

        $startedAt = now();
        $startedNanoseconds = hrtime(true);

        try {
            $response = $request();
            $this->store(
                $account,
                $user,
                $conversation,
                $message,
                $channel,
                $provider,
                $model,
                $requestType,
                $providerRound,
                AiProviderRequest::StatusSucceeded,
                $startedAt,
                $startedNanoseconds,
                $response,
            );

            return $response;
        } catch (StudioAiUsageLimitExceeded $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            $this->store(
                $account,
                $user,
                $conversation,
                $message,
                $channel,
                $provider,
                $model,
                $requestType,
                $providerRound,
                AiProviderRequest::StatusFailed,
                $startedAt,
                $startedNanoseconds,
                errorCode: class_basename($throwable),
            );

            throw $throwable;
        }
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    private function store(
        Account $account,
        ?User $user,
        ?AiConversation $conversation,
        ?AiConversationMessage $message,
        string $channel,
        AiProvider $provider,
        string $model,
        string $requestType,
        ?int $providerRound,
        string $status,
        Carbon $startedAt,
        int $startedNanoseconds,
        ?array $response = null,
        ?string $errorCode = null,
    ): void {
        $usage = $this->usage($provider, $response);

        AiProviderRequest::query()->create([
            'account_id' => $account->id,
            'user_id' => $user?->id,
            'ai_conversation_id' => $conversation?->id,
            'ai_conversation_message_id' => $message?->id,
            'channel' => $channel,
            'provider' => $provider->value,
            'model' => $model,
            'request_type' => $requestType,
            'provider_round' => $providerRound,
            'status' => $status,
            'provider_request_id' => $this->nullableString(Arr::get($response ?? [], 'raw.id')),
            ...$usage,
            'duration_ms' => max(0, (int) round((hrtime(true) - $startedNanoseconds) / 1_000_000)),
            'error_code' => $errorCode,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @return array{input_tokens: int|null, cached_input_tokens: int|null, output_tokens: int|null, reasoning_tokens: int|null, total_tokens: int|null}
     */
    private function usage(AiProvider $provider, ?array $response): array
    {
        $raw = is_array($response['raw'] ?? null) ? $response['raw'] : [];

        if ($provider === AiProvider::OpenAiApiKey) {
            return [
                'input_tokens' => $this->nullableInteger(Arr::get($raw, 'usage.input_tokens')),
                'cached_input_tokens' => $this->nullableInteger(Arr::get($raw, 'usage.input_tokens_details.cached_tokens')),
                'output_tokens' => $this->nullableInteger(Arr::get($raw, 'usage.output_tokens')),
                'reasoning_tokens' => $this->nullableInteger(Arr::get($raw, 'usage.output_tokens_details.reasoning_tokens')),
                'total_tokens' => $this->nullableInteger(Arr::get($raw, 'usage.total_tokens')),
            ];
        }

        $inputTokens = $this->nullableInteger(Arr::get($raw, 'prompt_eval_count'));
        $outputTokens = $this->nullableInteger(Arr::get($raw, 'eval_count'));

        return [
            'input_tokens' => $inputTokens,
            'cached_input_tokens' => null,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => null,
            'total_tokens' => $inputTokens !== null && $outputTokens !== null
                ? $inputTokens + $outputTokens
                : null,
        ];
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
