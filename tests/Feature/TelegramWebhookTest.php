<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\AiConversationMessage;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\TelegramAuthorizationSelectionCandidate;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_bot_authorizes_chat_from_shared_contact(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $owner = User::factory()->create(['phone' => '+380671112233']);
        $account = Account::factory()->create(['country_code' => 'UA']);
        $account->addOwner($owner);
        [$installation, $webhookKey] = $this->ownerInstallation();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1001,
            'message' => [
                'message_id' => 10,
                'chat' => ['id' => 555],
                'from' => ['id' => 777, 'username' => 'owner'],
                'contact' => [
                    'user_id' => 777,
                    'phone_number' => '+380671112233',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_chat_authorizations', [
            'account_id' => $account->id,
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_id' => '555',
            'user_id' => $owner->id,
            'profile' => TelegramBotProfile::Owner->value,
        ]);

        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '555',
            'direction' => 'outbound',
            'text' => __('app.telegram_authorized'),
        ]);
    }

    public function test_owner_bot_rejects_typed_or_forwarded_contact(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $owner = User::factory()->create(['phone' => '+380671112233']);
        $account = Account::factory()->create(['country_code' => 'UA']);
        $account->addOwner($owner);
        [$installation, $webhookKey] = $this->ownerInstallation();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1002,
            'message' => [
                'message_id' => 11,
                'chat' => ['id' => 556],
                'from' => ['id' => 777, 'username' => 'owner'],
                'contact' => [
                    'user_id' => 888,
                    'phone_number' => '+380671112233',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertFalse(TelegramChatAuthorization::where('telegram_chat_id', '556')->exists());
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '556',
            'direction' => 'outbound',
            'text' => __('app.telegram_authorization_failed'),
        ]);
    }

    public function test_owner_bot_unknown_phone_gets_signup_prompt(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        [$installation, $webhookKey] = $this->ownerInstallation();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1005,
            'message' => [
                'message_id' => 14,
                'chat' => ['id' => 559],
                'from' => ['id' => 779, 'username' => 'unknown'],
                'contact' => [
                    'user_id' => 779,
                    'phone_number' => '+380671119999',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertFalse(TelegramChatAuthorization::where('telegram_chat_id', '559')->exists());
        $this->assertTrue(TelegramMessage::where('telegram_chat_id', '559')
            ->where('direction', 'outbound')
            ->where('text', 'like', 'Ви не є клієнтом Ladna%')
            ->exists());
    }

    public function test_owner_bot_multi_studio_phone_uses_callback_selection(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $owner = User::factory()->create(['phone' => '+380671112233']);
        $firstAccount = Account::factory()->create(['name' => 'First Studio', 'country_code' => 'UA']);
        $secondAccount = Account::factory()->create(['name' => 'Second Studio', 'country_code' => 'UA']);
        $firstAccount->addOwner($owner);
        $secondAccount->addOwner($owner);
        [$installation, $webhookKey] = $this->ownerInstallation();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1006,
            'message' => [
                'message_id' => 15,
                'chat' => ['id' => 560],
                'from' => ['id' => 780, 'username' => 'owner'],
                'contact' => [
                    'user_id' => 780,
                    'phone_number' => '+380671112233',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '560',
            'direction' => 'outbound',
            'text' => __('app.telegram_choose_studio'),
        ]);

        $candidate = TelegramAuthorizationSelectionCandidate::where('account_id', $firstAccount->id)->firstOrFail();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1007,
            'callback_query' => [
                'id' => 'callback-1',
                'from' => ['id' => 780, 'username' => 'owner'],
                'message' => [
                    'message_id' => 16,
                    'chat' => ['id' => 560],
                ],
                'data' => 'tg_select:'.$candidate->id,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_chat_authorizations', [
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_id' => '560',
            'account_id' => $firstAccount->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_authorized_owner_text_is_stored_in_conversation_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $owner = User::factory()->create(['phone' => '+380671112233']);
        $account = Account::factory()->create(['country_code' => 'UA']);
        $account->addOwner($owner);
        PlatformAiSetting::query()->delete();
        PlatformAiSetting::factory()->create([
            'owner_ai_assistant_enabled' => true,
            'active_provider' => null,
            'active_model' => null,
        ]);
        [$installation, $webhookKey] = $this->ownerInstallation();

        TelegramChatAuthorization::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'user_id' => $owner->id,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => '557',
            'telegram_user_id' => '777',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1003,
            'message' => [
                'message_id' => 12,
                'chat' => ['id' => 557],
                'from' => ['id' => 777, 'username' => 'owner'],
                'text' => 'How many classes today?',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertTrue(AiConversationMessage::where('content', 'How many classes today?')->exists());
        $this->assertTrue(TelegramMessage::where('telegram_chat_id', '557')
            ->where('direction', 'outbound')
            ->where('text', __('app.telegram_class_count_for_day', ['date' => '2026-06-28', 'count' => 0]))
            ->exists());

        Carbon::setTestNow();
    }

    public function test_authorized_owner_text_uses_ai_when_ollama_provider_is_configured(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
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
                        'content' => 'AI answer for studio schedule.',
                    ],
                ]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $owner = User::factory()->create(['phone' => '+380671112233']);
        $account = Account::factory()->create(['country_code' => 'UA']);
        $account->addOwner($owner);
        PlatformAiSetting::query()->delete();
        PlatformAiProviderCredential::query()->delete();
        PlatformAiSetting::factory()->create([
            'owner_ai_assistant_enabled' => true,
            'active_provider' => AiProvider::OllamaCloud->value,
            'active_model' => 'gemma3:27b-cloud',
        ]);
        PlatformAiProviderCredential::factory()->create([
            'provider' => AiProvider::OllamaCloud->value,
            'model' => 'gemma3:27b-cloud',
            'credentials' => ['api_key' => 'test-ollama-key'],
            'is_configured' => true,
        ]);
        [$installation, $webhookKey] = $this->ownerInstallation();

        TelegramChatAuthorization::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'user_id' => $owner->id,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => '558',
            'telegram_user_id' => '778',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1004,
            'message' => [
                'message_id' => 13,
                'chat' => ['id' => 558],
                'from' => ['id' => 778, 'username' => 'owner'],
                'text' => 'How many classes today?',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '558',
            'direction' => 'outbound',
            'text' => 'AI answer for studio schedule.',
        ]);
        $this->assertTrue(AiConversationMessage::where('content', 'AI answer for studio schedule.')
            ->where('metadata->used_ai', true)
            ->exists());

        Carbon::setTestNow();
    }

    /**
     * @return array{TelegramBotInstallation, string}
     */
    private function ownerInstallation(): array
    {
        $webhookKey = 'tg_'.Str::random(24);
        $webhookSecret = Str::random(32);

        $installation = TelegramBotInstallation::factory()->platformOwner()->create([
            'profile' => TelegramBotProfile::Owner->value,
            'encrypted_webhook_key' => $webhookKey,
            'webhook_key_hash' => TelegramBotInstallation::hashWebhookSecret($webhookKey),
            'encrypted_webhook_secret' => $webhookSecret,
            'webhook_secret_token_hash' => TelegramBotInstallation::hashWebhookSecret($webhookSecret),
            'is_enabled' => true,
        ]);

        return [$installation, $webhookKey];
    }
}
