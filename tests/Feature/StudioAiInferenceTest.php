<?php

namespace Tests\Feature;

use App\Enums\AiConversationMessageRole;
use App\Enums\AiProvider;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\TelegramChatAuthorization;
use App\Models\User;
use App\Support\Ai\StudioAiInference;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioAiInferenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ollama_cloud_inference_uses_configured_provider_model_and_context(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => "```json\n{\"in_scope\":true,\"reason\":\"studio schedule question\"}\n```",
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'There are no scheduled classes today.',
                    ],
                ]),
        ]);

        $account = $this->accountWithOllamaSettings();

        $result = app(StudioAiInference::class)->respond($account, 'How many classes today?');

        $this->assertTrue($result->usedAi);
        $this->assertFalse($result->rejected);
        $this->assertSame('There are no scheduled classes today.', $result->text);
        $this->assertSame(AiProvider::OllamaCloud->value, $result->provider);
        $this->assertSame('gemma3:27b-cloud', $result->model);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://ollama.com/api/chat'
                && $payload['model'] === 'gemma3:27b-cloud'
                && ($payload['format'] ?? null) === 'json'
                && str_contains($payload['messages'][0]['content'], 'strict scope classifier')
                && str_contains($payload['messages'][1]['content'], 'How many classes today?');
        });

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://ollama.com/api/chat'
                && $payload['model'] === 'gemma3:27b-cloud'
                && $payload['stream'] === false
                && str_contains($payload['messages'][1]['content'], 'Studio context JSON')
                && str_contains($payload['messages'][1]['content'], 'How many classes today?');
        });

        Http::assertSentCount(2);
    }

    public function test_out_of_scope_prompt_is_rejected_before_answer_request(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => "```json\n{\"in_scope\":false,\"reason\":\"recipe request\"}\n```",
                ],
            ]),
        ]);

        $account = $this->accountWithOllamaSettings();

        $result = app(StudioAiInference::class)->respond($account, 'Give me a pie recipe');

        $this->assertFalse($result->usedAi);
        $this->assertTrue($result->rejected);
        $this->assertSame(__('app.telegram_out_of_scope'), $result->text);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return ($payload['format'] ?? null) === 'json'
                && str_contains($payload['messages'][1]['content'], 'Give me a pie recipe');
        });
        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->data()['messages'][1]['content'] ?? '', 'Studio context JSON');
        });
        Http::assertSentCount(1);
    }

    public function test_invalid_scope_classifier_response_is_rejected_before_answer_request(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Yes, this looks fine.',
                ],
            ]),
        ]);

        $account = $this->accountWithOllamaSettings();

        $result = app(StudioAiInference::class)->respond($account, 'How many classes today?');

        $this->assertFalse($result->usedAi);
        $this->assertTrue($result->rejected);
        $this->assertSame(__('app.telegram_out_of_scope'), $result->text);

        Http::assertSentCount(1);
    }

    public function test_inference_includes_recent_chat_history(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => "```json\n{\"in_scope\":true,\"reason\":\"studio schedule follow-up\"}\n```",
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'The previous answer is still relevant.',
                    ],
                ]),
        ]);

        $account = $this->accountWithOllamaSettings();
        $user = User::factory()->create(['phone' => '+380671112233']);
        $account->addOwner($user);
        $authorization = TelegramChatAuthorization::factory()->for($account)->create([
            'user_id' => $user->id,
            'profile' => TelegramBotProfile::Owner->value,
        ]);
        $conversation = AiConversation::factory()->for($account)->create([
            'telegram_chat_authorization_id' => $authorization->id,
            'channel' => 'telegram_owner',
            'profile' => TelegramBotProfile::Owner->value,
        ]);
        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => 'How many classes tomorrow?',
            'occurred_at' => now()->subMinute(),
        ]);
        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::Assistant->value,
            'content' => 'There are 0 scheduled classes tomorrow.',
            'occurred_at' => now(),
        ]);

        app(StudioAiInference::class)->respond($account, 'What about today?', $authorization);

        Http::assertSent(function (Request $request): bool {
            $messages = $request->data()['messages'];

            return collect($messages)->contains(fn (array $message): bool => $message['role'] === 'user' && $message['content'] === 'How many classes tomorrow?')
                && collect($messages)->contains(fn (array $message): bool => $message['role'] === 'assistant' && $message['content'] === 'There are 0 scheduled classes tomorrow.');
        });
    }

    private function accountWithOllamaSettings(): Account
    {
        $account = Account::factory()->create(['name' => 'Charmpole', 'timezone' => 'Europe/Kyiv']);

        PlatformAiSetting::query()->delete();
        PlatformAiProviderCredential::query()->delete();

        PlatformAiSetting::factory()->create([
            'owner_ai_assistant_enabled' => true,
            'active_provider' => AiProvider::OllamaCloud->value,
            'active_model' => 'gemma3:27b-cloud',
            'bot_display_name' => 'Ladna assistant',
            'internal_instructions' => 'Answer briefly.',
        ]);
        PlatformAiProviderCredential::factory()->create([
            'provider' => AiProvider::OllamaCloud->value,
            'model' => 'gemma3:27b-cloud',
            'credentials' => ['api_key' => 'test-ollama-key'],
            'is_configured' => true,
        ]);

        return $account;
    }
}
