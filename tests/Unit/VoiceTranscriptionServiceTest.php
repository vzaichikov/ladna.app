<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
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
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VoiceTranscriptionServiceTest extends TestCase
{
    public function test_it_transcribes_with_the_general_key_and_records_the_provider_call(): void
    {
        config(['services.openai.transcription_model' => 'gpt-transcribe-test']);
        $account = $this->account();
        $user = $this->user();
        $setting = $this->enabledSetting();
        $normalizedPath = $this->temporaryAudio('normalized-audio');
        $normalizedAudio = new NormalizedVoiceAudio($normalizedPath);
        $audioNormalizer = $this->createMock(VoiceAudioNormalizer::class);
        $audioNormalizer->expects($this->once())
            ->method('normalize')
            ->with('source-audio')
            ->willReturn($normalizedAudio);
        $provider = $this->createMock(VoiceTranscriptionProvider::class);
        $provider->expects($this->once())
            ->method('transcribe')
            ->with($normalizedPath, 'general-openai-key', 'gpt-transcribe-test')
            ->willReturn([
                'text' => '  Запиши мене на тренування.  ',
                'raw' => ['usage' => ['total_tokens' => 10]],
            ]);
        $providerResolver = $this->createMock(VoiceTranscriptionProviderResolver::class);
        $providerResolver->expects($this->once())
            ->method('resolve')
            ->with(VoiceRecognitionProvider::OpenAi)
            ->willReturn($provider);
        $credentialResolver = $this->createMock(VoiceTranscriptionCredentialResolver::class);
        $credentialResolver->expects($this->once())
            ->method('resolve')
            ->with(VoiceRecognitionProvider::OpenAi)
            ->willReturn('general-openai-key');
        $recorder = $this->createMock(AiProviderRequestRecorder::class);
        $recorder->expects($this->once())
            ->method('record')
            ->willReturnCallback(function (...$arguments) use ($account, $user, $setting): array {
                $this->assertSame($account, $arguments[0]);
                $this->assertSame($user, $arguments[1]);
                $this->assertNull($arguments[2]);
                $this->assertNull($arguments[3]);
                $this->assertSame('dashboard_chat', $arguments[4]);
                $this->assertSame(AiProvider::OpenAiApiKey, $arguments[5]);
                $this->assertSame('gpt-transcribe-test', $arguments[6]);
                $this->assertSame(AiProviderRequest::TypeTranscription, $arguments[7]);
                $this->assertNull($arguments[8]);
                $this->assertSame($setting, $arguments[10]);

                return $arguments[9]();
            });

        $text = (new VoiceTranscriptionService(
            $audioNormalizer,
            $providerResolver,
            $credentialResolver,
            $recorder,
        ))->transcribe(
            'source-audio',
            $account,
            $user,
            'dashboard_chat',
            $setting,
        );

        $this->assertSame('Запиши мене на тренування.', $text);
        $this->assertFileDoesNotExist($normalizedPath);
    }

    public function test_it_rejects_a_concurrent_transcription_without_normalizing_audio(): void
    {
        $user = $this->user();
        $channel = 'telegram_owner';
        $lock = Cache::lock($this->lockKey($user, $channel), 180);
        $this->assertTrue($lock->get());
        $audioNormalizer = $this->createMock(VoiceAudioNormalizer::class);
        $audioNormalizer->expects($this->never())->method('normalize');
        $provider = $this->createMock(VoiceTranscriptionProvider::class);
        $providerResolver = $this->createMock(VoiceTranscriptionProviderResolver::class);
        $providerResolver->method('resolve')->willReturn($provider);
        $credentialResolver = $this->createMock(VoiceTranscriptionCredentialResolver::class);
        $credentialResolver->method('resolve')->willReturn('general-openai-key');
        $recorder = $this->createMock(AiProviderRequestRecorder::class);
        $recorder->expects($this->never())->method('record');

        try {
            (new VoiceTranscriptionService(
                $audioNormalizer,
                $providerResolver,
                $credentialResolver,
                $recorder,
            ))->transcribe(
                'source-audio',
                $this->account(),
                $user,
                $channel,
                $this->enabledSetting(),
            );
            $this->fail('A concurrent transcription should throw.');
        } catch (VoiceTranscriptionException $exception) {
            $this->assertSame('busy', $exception->reason());
        } finally {
            $lock->release();
        }
    }

    public function test_it_releases_the_audio_and_lock_when_the_provider_fails(): void
    {
        $normalizedPath = $this->temporaryAudio('normalized-audio');
        $audioNormalizer = $this->createMock(VoiceAudioNormalizer::class);
        $audioNormalizer->method('normalize')->willReturn(new NormalizedVoiceAudio($normalizedPath));
        $provider = $this->createMock(VoiceTranscriptionProvider::class);
        $provider->method('transcribe')
            ->willThrowException(new VoiceTranscriptionException('provider_failed'));
        $providerResolver = $this->createMock(VoiceTranscriptionProviderResolver::class);
        $providerResolver->method('resolve')->willReturn($provider);
        $credentialResolver = $this->createMock(VoiceTranscriptionCredentialResolver::class);
        $credentialResolver->method('resolve')->willReturn('general-openai-key');
        $recorder = $this->createMock(AiProviderRequestRecorder::class);
        $recorder->method('record')
            ->willReturnCallback(fn (...$arguments): array => $arguments[9]());
        $user = $this->user();
        $channel = 'dashboard_chat';

        try {
            (new VoiceTranscriptionService(
                $audioNormalizer,
                $providerResolver,
                $credentialResolver,
                $recorder,
            ))->transcribe(
                'source-audio',
                $this->account(),
                $user,
                $channel,
                $this->enabledSetting(),
            );
            $this->fail('A provider failure should throw.');
        } catch (VoiceTranscriptionException $exception) {
            $this->assertSame('provider_failed', $exception->reason());
        }

        $this->assertFileDoesNotExist($normalizedPath);
        $releasedLock = Cache::lock($this->lockKey($user, $channel), 180);
        $this->assertTrue($releasedLock->get());
        $releasedLock->release();
    }

    public function test_it_rejects_an_overlong_transcript_without_truncating_it(): void
    {
        $normalizedPath = $this->temporaryAudio('normalized-audio');
        $audioNormalizer = $this->createMock(VoiceAudioNormalizer::class);
        $audioNormalizer->method('normalize')->willReturn(new NormalizedVoiceAudio($normalizedPath));
        $provider = $this->createMock(VoiceTranscriptionProvider::class);
        $provider->method('transcribe')->willReturn([
            'text' => str_repeat('ї', VoiceTranscriptionService::MaxTranscriptCharacters + 1),
            'raw' => [],
        ]);
        $providerResolver = $this->createMock(VoiceTranscriptionProviderResolver::class);
        $providerResolver->method('resolve')->willReturn($provider);
        $credentialResolver = $this->createMock(VoiceTranscriptionCredentialResolver::class);
        $credentialResolver->method('resolve')->willReturn('general-openai-key');
        $recorder = $this->createMock(AiProviderRequestRecorder::class);
        $recorder->method('record')
            ->willReturnCallback(fn (...$arguments): array => $arguments[9]());

        try {
            (new VoiceTranscriptionService(
                $audioNormalizer,
                $providerResolver,
                $credentialResolver,
                $recorder,
            ))->transcribe(
                'source-audio',
                $this->account(),
                $this->user(),
                'dashboard_chat',
                $this->enabledSetting(),
            );
            $this->fail('An overlong transcript should throw.');
        } catch (VoiceTranscriptionException $exception) {
            $this->assertSame('transcript_too_long', $exception->reason());
        }

        $this->assertFileDoesNotExist($normalizedPath);
    }

    public function test_it_fails_before_resolving_credentials_when_voice_is_disabled(): void
    {
        $audioNormalizer = $this->createMock(VoiceAudioNormalizer::class);
        $audioNormalizer->expects($this->never())->method('normalize');
        $providerResolver = $this->createMock(VoiceTranscriptionProviderResolver::class);
        $providerResolver->expects($this->never())->method('resolve');
        $credentialResolver = $this->createMock(VoiceTranscriptionCredentialResolver::class);
        $credentialResolver->expects($this->never())->method('resolve');
        $recorder = $this->createMock(AiProviderRequestRecorder::class);
        $recorder->expects($this->never())->method('record');
        $setting = $this->enabledSetting();
        $setting->owner_voice_input_enabled = false;

        try {
            (new VoiceTranscriptionService(
                $audioNormalizer,
                $providerResolver,
                $credentialResolver,
                $recorder,
            ))->transcribe(
                'source-audio',
                $this->account(),
                $this->user(),
                'dashboard_chat',
                $setting,
            );
            $this->fail('Disabled voice input should throw.');
        } catch (VoiceTranscriptionException $exception) {
            $this->assertSame('provider_unavailable', $exception->reason());
        }
    }

    private function account(): Account
    {
        $account = new Account;
        $account->id = 101;

        return $account;
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 202;

        return $user;
    }

    private function enabledSetting(): PlatformAiSetting
    {
        return new PlatformAiSetting([
            'owner_ai_assistant_enabled' => true,
            'owner_voice_input_enabled' => true,
            'owner_voice_recognition_provider' => VoiceRecognitionProvider::OpenAi->value,
        ]);
    }

    private function temporaryAudio(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ladna-voice-service-test-');

        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }

    private function lockKey(User $user, string $channel): string
    {
        return 'ai:voice-transcription:user:'.$user->id.':channel:'.hash('sha256', $channel);
    }
}
