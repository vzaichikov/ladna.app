<?php

namespace App\Support\Ai\Voice;

use App\Enums\AiProvider;
use App\Enums\VoiceRecognitionProvider;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiProviderRequest;
use App\Models\PlatformAiSetting;
use App\Models\User;
use App\Support\Ai\AiProviderRequestRecorder;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class VoiceTranscriptionService
{
    public const MaxTranscriptCharacters = 2000;

    private const LockSeconds = 360;

    public function __construct(
        private readonly VoiceAudioNormalizer $audioNormalizer,
        private readonly VoiceTranscriptionProviderResolver $providerResolver,
        private readonly VoiceTranscriptionCredentialResolver $credentialResolver,
        private readonly AiProviderRequestRecorder $providerRequestRecorder,
    ) {}

    public function transcribe(
        string $audioContents,
        Account $account,
        User $user,
        string $channel,
        PlatformAiSetting $setting,
        ?AiConversation $conversation = null,
    ): string {
        if (! $setting->owner_ai_assistant_enabled || ! $setting->owner_voice_input_enabled) {
            throw new VoiceTranscriptionException('provider_unavailable');
        }

        $voiceProvider = $setting->owner_voice_recognition_provider;

        if (! $voiceProvider instanceof VoiceRecognitionProvider) {
            throw new VoiceTranscriptionException('provider_unavailable');
        }

        $provider = $this->providerResolver->resolve($voiceProvider);
        $apiKey = $this->credentialResolver->resolve($voiceProvider);
        $lock = $this->acquireLock($user, $channel);

        if (! $lock) {
            throw new VoiceTranscriptionException('busy');
        }

        $normalizedAudio = null;

        try {
            $normalizedAudio = $this->audioNormalizer->normalize($audioContents);
            $model = $this->transcriptionModel();
            $response = $this->providerRequestRecorder->record(
                $account,
                $user,
                $conversation,
                null,
                $channel,
                AiProvider::OpenAiApiKey,
                $model,
                AiProviderRequest::TypeTranscription,
                null,
                fn (): array => $this->validatedResponse(
                    $provider->transcribe($normalizedAudio->path, $apiKey, $model),
                ),
                $setting,
            );

            return $response['text'];
        } finally {
            if ($normalizedAudio && ! $normalizedAudio->release()) {
                report(new RuntimeException('Unable to remove temporary normalized voice audio.'));
            }

            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{text: string, raw: array<string, mixed>}
     */
    private function validatedResponse(array $response): array
    {
        $text = is_string($response['text'] ?? null)
            ? trim($response['text'])
            : '';

        if ($text === '') {
            throw new VoiceTranscriptionException('empty_transcript');
        }

        if (mb_strlen($text) > self::MaxTranscriptCharacters) {
            throw new VoiceTranscriptionException('transcript_too_long');
        }

        $raw = $response['raw'] ?? null;

        return [
            'text' => $text,
            'raw' => is_array($raw) ? $raw : [],
        ];
    }

    private function acquireLock(User $user, string $channel): ?Lock
    {
        $key = 'ai:voice-transcription:user:'.$user->id.':channel:'.hash('sha256', $channel);
        $lock = Cache::lock($key, self::LockSeconds);

        return $lock->get() ? $lock : null;
    }

    private function transcriptionModel(): string
    {
        $model = trim((string) config('services.openai.transcription_model', 'gpt-transcribe'));

        return $model !== '' ? $model : 'gpt-transcribe';
    }
}
