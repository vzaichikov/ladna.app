<?php

namespace Tests\Feature;

use App\Enums\VoiceRecognitionProvider;
use App\Models\Account;
use App\Models\AiProviderRequest;
use App\Models\PlatformAiSetting;
use App\Models\User;
use App\Support\Ai\AiProviderRequestRecorder;
use App\Support\Ai\Voice\NormalizedVoiceAudio;
use App\Support\Ai\Voice\VoiceAudioNormalizer;
use App\Support\Ai\Voice\VoiceTranscriptionCredentialResolver;
use App\Support\Ai\Voice\VoiceTranscriptionException;
use App\Support\Ai\Voice\VoiceTranscriptionProvider;
use App\Support\Ai\Voice\VoiceTranscriptionProviderResolver;
use App\Support\Ai\Voice\VoiceTranscriptionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VoiceTranscriptionAuditTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_overlong_transcript_is_audited_as_a_failed_provider_request(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create();
        $setting = PlatformAiSetting::factory()->create([
            'owner_ai_assistant_enabled' => true,
            'owner_voice_input_enabled' => true,
            'owner_voice_recognition_provider' => VoiceRecognitionProvider::OpenAi->value,
            'firewall_enabled' => false,
        ]);
        $normalizedPath = $this->temporaryAudio('normalized-audio');
        $audioNormalizer = $this->createMock(VoiceAudioNormalizer::class);
        $audioNormalizer->method('normalize')->willReturn(new NormalizedVoiceAudio($normalizedPath));
        $provider = $this->createMock(VoiceTranscriptionProvider::class);
        $provider->method('transcribe')->willReturn([
            'text' => str_repeat('ї', VoiceTranscriptionService::MaxTranscriptCharacters + 1),
            'raw' => [
                'id' => 'transcription-sensitive-id',
                'usage' => [
                    'input_tokens' => 20,
                    'output_tokens' => 5,
                    'total_tokens' => 25,
                ],
            ],
        ]);
        $providerResolver = $this->createMock(VoiceTranscriptionProviderResolver::class);
        $providerResolver->method('resolve')->willReturn($provider);
        $credentialResolver = $this->createMock(VoiceTranscriptionCredentialResolver::class);
        $credentialResolver->method('resolve')->willReturn('general-openai-key');
        $service = new VoiceTranscriptionService(
            $audioNormalizer,
            $providerResolver,
            $credentialResolver,
            app(AiProviderRequestRecorder::class),
        );

        try {
            $service->transcribe(
                'source-audio',
                $account,
                $user,
                'dashboard_chat',
                $setting,
            );
            $this->fail('An overlong transcript should throw.');
        } catch (VoiceTranscriptionException $exception) {
            $this->assertSame('transcript_too_long', $exception->reason());
        }

        $providerRequest = AiProviderRequest::query()->sole();

        $this->assertSame(AiProviderRequest::TypeTranscription, $providerRequest->request_type);
        $this->assertSame(AiProviderRequest::StatusFailed, $providerRequest->status);
        $this->assertSame('transcript_too_long', $providerRequest->error_code);
        $this->assertNull($providerRequest->provider_request_id);
        $this->assertNull($providerRequest->total_tokens);
        $this->assertFileDoesNotExist($normalizedPath);
    }

    private function temporaryAudio(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ladna-voice-audit-test-');

        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
