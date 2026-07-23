<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\ScheduleKind;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiPendingAction;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\TelegramAuthorizationSelectionCandidate;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    public function test_owner_bot_authorization_links_trainer_by_shared_phone(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $owner = User::factory()->create(['phone' => '+380671112233']);
        $account = Account::factory()->create(['country_code' => 'UA']);
        $account->addOwner($owner);
        $trainer = Trainer::factory()->for($account)->create([
            'phone' => '+380671112233',
            'user_id' => null,
            'is_active' => true,
        ]);
        [$installation, $webhookKey] = $this->ownerInstallation();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10011,
            'message' => [
                'message_id' => 101,
                'chat' => ['id' => 5511],
                'from' => ['id' => 7711, 'username' => 'owner'],
                'contact' => [
                    'user_id' => 7711,
                    'phone_number' => '+380671112233',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_chat_authorizations', [
            'account_id' => $account->id,
            'telegram_chat_id' => '5511',
            'user_id' => $owner->id,
            'trainer_id' => $trainer->id,
        ]);
    }

    public function test_owner_bot_deduplicates_owner_and_trainer_candidates_for_same_studio_phone(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $owner = User::factory()->create(['phone' => '+380001234567']);
        $trainerUser = User::factory()->create(['phone' => null]);
        $account = Account::factory()->create(['name' => 'Test Studio', 'country_code' => 'UA']);
        $account->addOwner($owner);
        AccountMembership::factory()
            ->for($account)
            ->for($trainerUser, 'user')
            ->create([
                'role' => 'trainer',
                'permissions' => ['interact_with_telegram_bot'],
            ]);
        $trainer = Trainer::factory()->for($account)->create([
            'name' => 'Test Trainer',
            'phone' => '+380001234567',
            'user_id' => $trainerUser->id,
            'is_active' => true,
        ]);
        [$installation, $webhookKey] = $this->ownerInstallation();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10012,
            'message' => [
                'message_id' => 102,
                'chat' => ['id' => 5512],
                'from' => ['id' => 7712, 'username' => 'owner'],
                'contact' => [
                    'user_id' => 7712,
                    'phone_number' => '+380001234567',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertFalse(TelegramAuthorizationSelectionCandidate::where('account_id', $account->id)->exists());
        $this->assertDatabaseHas('telegram_chat_authorizations', [
            'account_id' => $account->id,
            'telegram_chat_id' => '5512',
            'user_id' => $owner->id,
            'trainer_id' => $trainer->id,
        ]);
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '5512',
            'direction' => 'outbound',
            'text' => __('app.telegram_authorized'),
        ]);
    }

    public function test_owner_bot_authorizes_trainer_login_when_phone_is_only_on_trainer_profile(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $trainerUser = User::factory()->create(['phone' => null]);
        $account = Account::factory()->create(['name' => 'Test Studio', 'country_code' => 'UA']);
        AccountMembership::factory()
            ->for($account)
            ->for($trainerUser, 'user')
            ->create([
                'role' => 'trainer',
                'permissions' => ['interact_with_telegram_bot'],
            ]);
        $trainer = Trainer::factory()->for($account)->create([
            'name' => 'Настя',
            'phone' => '+380509520618',
            'user_id' => $trainerUser->id,
            'is_active' => true,
        ]);
        [$installation, $webhookKey] = $this->ownerInstallation();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10013,
            'message' => [
                'message_id' => 103,
                'chat' => ['id' => 5513],
                'from' => ['id' => 7713, 'username' => 'trainer'],
                'contact' => [
                    'user_id' => 7713,
                    'phone_number' => '+380509520618',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_chat_authorizations', [
            'account_id' => $account->id,
            'telegram_chat_id' => '5513',
            'user_id' => $trainerUser->id,
            'trainer_id' => $trainer->id,
            'status' => 'authorized',
        ]);
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '5513',
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
            ->where('text', __('app.telegram_unknown_phone_signup', ['url' => route('demo.login')]))
            ->exists());
    }

    public function test_owner_bot_start_prompts_unauthorized_chat_to_share_phone(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        [$installation, $webhookKey] = $this->ownerInstallation();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1010,
            'message' => [
                'message_id' => 17,
                'chat' => ['id' => 561],
                'from' => ['id' => 781, 'username' => 'owner'],
                'text' => '/start',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $message = TelegramMessage::where('telegram_chat_id', '561')
            ->where('direction', 'outbound')
            ->firstOrFail();

        $this->assertSame(__('app.telegram_share_contact_to_authorize'), $message->text);
        $this->assertTrue((bool) data_get($message->payload, 'reply_markup.keyboard.0.0.request_contact'));
        $this->assertSame(__('app.telegram_share_phone_button'), data_get($message->payload, 'reply_markup.keyboard.0.0.text'));
    }

    public function test_owner_bot_authorization_removes_contact_keyboard_when_assistant_is_enabled(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        PlatformAiSetting::query()->delete();
        PlatformAiSetting::factory()->create(['owner_ai_assistant_enabled' => true]);

        $owner = User::factory()->create(['phone' => '+380671112233']);
        $account = Account::factory()->create(['country_code' => 'UA']);
        $account->addOwner($owner);
        [$installation, $webhookKey] = $this->ownerInstallation();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1011,
            'message' => [
                'message_id' => 18,
                'chat' => ['id' => 562],
                'from' => ['id' => 782, 'username' => 'owner'],
                'contact' => [
                    'user_id' => 782,
                    'phone_number' => '+380671112233',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '562'
            && $request['text'] === __('app.telegram_authorized')
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
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
            ->where('text', __('app.assistant_ai_unavailable'))
            ->exists());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendChatAction')
            && $request['chat_id'] === '557'
            && $request['action'] === 'typing');

        Carbon::setTestNow();
    }

    public function test_owner_quick_action_starts_booking_dialog_without_ai_request(): void
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
            'telegram_chat_id' => '563',
            'telegram_user_id' => '783',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1012,
            'message' => [
                'message_id' => 19,
                'chat' => ['id' => 563],
                'from' => ['id' => 783, 'username' => 'owner'],
                'text' => __('app.telegram_quick_action_create_booking'),
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '563',
            'direction' => 'outbound',
            'text' => __('app.assistant_booking_dialog_customer_missing'),
        ]);

        $assistantMessage = AiConversationMessage::where('content', __('app.assistant_booking_dialog_customer_missing'))->firstOrFail();

        $this->assertSame('awaiting_customer', data_get($assistantMessage->metadata, 'booking_dialog.status'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendChatAction')
            && $request['chat_id'] === '563'
            && $request['action'] === 'typing');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '563'
            && data_get($request->data(), 'parse_mode') === 'HTML'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === __('app.assistant_booking_dialog_cancel_button')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'tg_booking:cancel');

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10121,
            'message' => [
                'message_id' => 191,
                'chat' => ['id' => 563],
                'from' => ['id' => 783, 'username' => 'owner'],
                'text' => '/book',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertSame(2, TelegramMessage::where('telegram_chat_id', '563')
            ->where('direction', 'outbound')
            ->where('text', __('app.assistant_booking_dialog_customer_missing'))
            ->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ollama.com/api/chat'));

        Carbon::setTestNow();
    }

    public function test_owner_booking_dialog_can_be_cancelled_by_natural_language(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"cancel_dialog","answer":null,"follow_up_actions":[],"action":{},"reason":"owner abandoned active booking dialog"}',
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
            'telegram_chat_id' => '568',
            'telegram_user_id' => '788',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10124,
            'message' => [
                'message_id' => 194,
                'chat' => ['id' => 568],
                'from' => ['id' => 788, 'username' => 'owner'],
                'text' => '/book',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $cancelText = 'Давай завершимо запис, я передумала';

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10125,
            'message' => [
                'message_id' => 195,
                'chat' => ['id' => 568],
                'from' => ['id' => 788, 'username' => 'owner'],
                'text' => $cancelText,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '568',
            'direction' => 'outbound',
            'text' => __('app.assistant_booking_dialog_cancelled'),
        ]);
        $this->assertFalse(TelegramMessage::where('telegram_chat_id', '568')
            ->where('direction', 'outbound')
            ->where('text', __('app.assistant_booking_dialog_customer_not_found', ['query' => $cancelText]))
            ->exists());

        $assistantMessage = AiConversationMessage::where('content', __('app.assistant_booking_dialog_cancelled'))->firstOrFail();

        $this->assertSame('cancelled', data_get($assistantMessage->metadata, 'booking_dialog.status'));

        Carbon::setTestNow();
    }

    public function test_owner_booking_help_question_does_not_start_booking_dialog(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'disposition' => 'answer',
                        'answer' => 'Якщо ви забули записати людину на заняття, перевірте розклад і додайте запис вручну з картки заняття.',
                        'follow_up_actions' => [],
                        'action' => null,
                        'reason' => 'studio booking workflow question',
                    ]),
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
            'telegram_chat_id' => '572',
            'telegram_user_id' => '792',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10130,
            'message' => [
                'message_id' => 200,
                'chat' => ['id' => 572],
                'from' => ['id' => 792, 'username' => 'owner'],
                'text' => 'А підкажи, що робити якщо я забула записати людину сьогодні на заняття?',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '572',
            'direction' => 'outbound',
            'text' => 'Якщо ви забули записати людину на заняття, перевірте розклад і додайте запис вручну з картки заняття.',
        ]);
        $this->assertFalse(TelegramMessage::where('telegram_chat_id', '572')
            ->where('direction', 'outbound')
            ->where('text', __('app.assistant_booking_dialog_customer_not_found', ['query' => 'людину']))
            ->exists());
        $this->assertFalse(AiConversationMessage::where('metadata->booking_dialog->status', 'awaiting_customer')->exists());

        $ollamaRequests = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => str_contains($request->url(), 'ollama.com/api/chat'))
            ->values();

        $this->assertCount(1, $ollamaRequests);
        $this->assertStringContainsString('Allowed disposition values', $ollamaRequests->first()->data()['messages'][0]['content'] ?? '');

        Carbon::setTestNow();
    }

    public function test_owner_booking_dialog_can_be_cancelled_from_inline_button(): void
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
            'telegram_chat_id' => '569',
            'telegram_user_id' => '789',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10126,
            'message' => [
                'message_id' => 196,
                'chat' => ['id' => 569],
                'from' => ['id' => 789, 'username' => 'owner'],
                'text' => '/book',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '569'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'tg_booking:cancel');

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10127,
            'callback_query' => [
                'id' => 'callback-booking-cancel-1',
                'from' => ['id' => 789, 'username' => 'owner'],
                'message' => [
                    'message_id' => 197,
                    'chat' => ['id' => 569],
                ],
                'data' => 'tg_booking:cancel',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '569',
            'direction' => 'inbound',
            'message_type' => 'callback_query',
            'text' => '/cancel_booking',
        ]);
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '569',
            'direction' => 'outbound',
            'text' => __('app.assistant_booking_dialog_cancelled'),
        ]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendChatAction')
            && $request['chat_id'] === '569'
            && $request['action'] === 'typing');

        Carbon::setTestNow();
    }

    public function test_owner_cancel_booking_command_without_active_dialog_returns_noop_message(): void
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
            'telegram_chat_id' => '570',
            'telegram_user_id' => '790',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10128,
            'message' => [
                'message_id' => 198,
                'chat' => ['id' => 570],
                'from' => ['id' => 790, 'username' => 'owner'],
                'text' => '/cancel_booking',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '570',
            'direction' => 'outbound',
            'text' => __('app.assistant_booking_dialog_no_active'),
        ]);

        Carbon::setTestNow();
    }

    public function test_authorized_owner_can_restart_stuck_telegram_conversation(): void
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

        $authorization = TelegramChatAuthorization::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'user_id' => $owner->id,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => '567',
            'telegram_user_id' => '787',
        ]);
        $conversation = AiConversation::factory()->for($account)->create([
            'telegram_chat_authorization_id' => $authorization->id,
            'user_id' => $owner->id,
            'channel' => 'telegram_owner',
            'profile' => TelegramBotProfile::Owner->value,
            'status' => AiConversation::StatusActive,
        ]);
        $action = AiPendingAction::factory()->for($account)->for($conversation, 'conversation')->for($owner, 'user')->create([
            'status' => AiPendingAction::StatusPending,
        ]);
        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => 'assistant',
            'content' => __('app.assistant_booking_dialog_customer_missing'),
            'metadata' => [
                'booking_dialog' => ['status' => 'awaiting_customer'],
            ],
            'occurred_at' => now(),
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10122,
            'message' => [
                'message_id' => 192,
                'chat' => ['id' => 567],
                'from' => ['id' => 787, 'username' => 'owner'],
                'text' => '/start',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertSame(AiConversation::StatusCleared, $conversation->fresh()->status);
        $this->assertSame(AiPendingAction::StatusCancelled, $action->fresh()->status);
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '567',
            'direction' => 'outbound',
            'text' => __('app.telegram_conversation_restarted'),
        ]);
        $this->assertFalse(TelegramMessage::where('telegram_chat_id', '567')
            ->where('direction', 'outbound')
            ->where('text', __('app.assistant_booking_dialog_customer_not_found', ['query' => '/start']))
            ->exists());

        $buttonConversation = AiConversation::factory()->for($account)->create([
            'telegram_chat_authorization_id' => $authorization->id,
            'user_id' => $owner->id,
            'channel' => 'telegram_owner',
            'profile' => TelegramBotProfile::Owner->value,
            'status' => AiConversation::StatusActive,
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10123,
            'callback_query' => [
                'id' => 'callback-restart-1',
                'from' => ['id' => 787, 'username' => 'owner'],
                'message' => [
                    'message_id' => 193,
                    'chat' => ['id' => 567],
                ],
                'data' => 'tg_restart',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertSame(AiConversation::StatusCleared, $buttonConversation->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_owner_booking_dialog_uses_authorized_trainer_and_accepts_class_name_reply(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00', 'Europe/Kiev'));
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"disposition":"start_booking","answer":null,"follow_up_actions":[],"action":{"customer_query":"Аліна Тестова","date":"2026-06-30","use_actor_trainer":true},"reason":"direct booking request"}',
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"disposition":"continue_booking","answer":null,"follow_up_actions":[],"action":{"option_number":1},"reason":"selected visible class option"}',
                    ],
                ]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        Mail::fake();

        $owner = User::factory()->create(['phone' => '+380671112233']);
        $account = Account::factory()->create(['country_code' => 'UA', 'timezone' => 'Europe/Kiev']);
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

        $location = Location::factory()->for($account)->create(['timezone' => 'Europe/Kiev']);
        $room = Room::factory()->for($account)->for($location)->create();
        $trainer = Trainer::factory()->for($account)->create([
            'name' => 'Test Trainer',
            'phone' => '+380671112233',
            'user_id' => null,
            'is_active' => true,
        ]);
        $exotType = ClassType::factory()->for($account)->create([
            'name' => 'Exot',
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $tricksType = ClassType::factory()->for($account)->create([
            'name' => 'Tricks',
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $exotClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($exotType)
            ->for($trainer)
            ->create([
                'starts_at' => Carbon::parse('2026-06-30 10:00:00', 'Europe/Kiev')->timezone('UTC'),
                'ends_at' => Carbon::parse('2026-06-30 11:00:00', 'Europe/Kiev')->timezone('UTC'),
                'capacity' => 8,
                'title' => 'Exot',
            ]);
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($tricksType)
            ->for($trainer)
            ->create([
                'starts_at' => Carbon::parse('2026-06-30 11:00:00', 'Europe/Kiev')->timezone('UTC'),
                'ends_at' => Carbon::parse('2026-06-30 12:00:00', 'Europe/Kiev')->timezone('UTC'),
                'capacity' => 8,
                'title' => 'Tricks',
            ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Аліна Тестова']);

        $authorization = TelegramChatAuthorization::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'user_id' => $owner->id,
            'trainer_id' => null,
            'phone' => '+380671112233',
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => '566',
            'telegram_user_id' => '786',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1017,
            'message' => [
                'message_id' => 24,
                'chat' => ['id' => 566],
                'from' => ['id' => 786, 'username' => 'owner'],
                'text' => 'Можемо до мене завтра Аліну записати?',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertSame($trainer->id, $authorization->fresh()->trainer_id);
        $classChoiceMessage = TelegramMessage::where('telegram_chat_id', '566')
            ->where('direction', 'outbound')
            ->where('text', 'like', '%Exot%')
            ->firstOrFail();

        $this->assertStringContainsString($customer->name, (string) $classChoiceMessage->text);
        $this->assertStringContainsString($trainer->name, (string) $classChoiceMessage->text);
        $this->assertStringContainsString('Tricks', (string) $classChoiceMessage->text);

        $assistantMessage = AiConversationMessage::where('content', $classChoiceMessage->text)->firstOrFail();
        $this->assertSame('awaiting_class', data_get($assistantMessage->metadata, 'booking_dialog.status'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendChatAction')
            && $request['chat_id'] === '566'
            && $request['action'] === 'typing');

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1018,
            'message' => [
                'message_id' => 25,
                'chat' => ['id' => 566],
                'from' => ['id' => 786, 'username' => 'owner'],
                'text' => 'Екзот',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $action = AiPendingAction::where('action_name', 'create-booking')->firstOrFail();

        $this->assertSame($customer->id, (int) data_get($action->arguments, 'customer_id'));
        $this->assertSame($exotClass->id, (int) data_get($action->arguments, 'scheduled_class_id'));
        $this->assertSame($trainer->id, $action->trainer_id);
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '566',
            'direction' => 'outbound',
            'text' => __('app.assistant_pending_action_created'),
        ]);

        Carbon::setTestNow();
    }

    public function test_authorized_owner_text_uses_ai_when_ollama_provider_is_configured(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        config(['services.telegram.typing_refresh_seconds' => 0]);
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'disposition' => 'answer',
                        'answer' => "**AI answer** for studio schedule.\n* First item",
                        'follow_up_actions' => [],
                        'action' => null,
                        'reason' => 'studio schedule question',
                    ]),
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
            'text' => "**AI answer** for studio schedule.\n* First item",
        ]);
        $this->assertTrue(AiConversationMessage::where('content', "**AI answer** for studio schedule.\n* First item")
            ->where('metadata->used_ai', true)
            ->exists());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendChatAction')
            && $request['chat_id'] === '558'
            && $request['action'] === 'typing');
        $this->assertGreaterThanOrEqual(4, collect(Http::recorded())
            ->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/sendChatAction'))
            ->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '558'
            && data_get($request->data(), 'parse_mode') === 'HTML'
            && $request['text'] === "<b>AI answer</b> for studio schedule.\n&#8226; First item");

        $telegramMethods = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/'))
            ->map(fn (Request $request): string => Str::afterLast($request->url(), '/'))
            ->values();

        $this->assertSame('sendChatAction', $telegramMethods->get($telegramMethods->count() - 2));
        $this->assertSame('sendMessage', $telegramMethods->last());

        Carbon::setTestNow();
    }

    public function test_telegram_contextual_option_reply_uses_same_conversation_snapshot_once(): void
    {
        $currentText = 'мені більше подобається третій варіант';
        $options = "Можу запропонувати:\n1. Skyler Flow\n2. Skyler Space\n3. Skyler Studio";
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"answer","answer":"Тоді обираємо Skyler Studio.","follow_up_actions":[],"action":null,"reason":"contextual selection from prior options"}',
                ],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $owner = User::factory()->create(['name' => 'Валерія', 'phone' => '+380671112299']);
        $account = Account::factory()->create(['name' => 'Skyler owner studio']);
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
        $authorization = TelegramChatAuthorization::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'user_id' => $owner->id,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => '576',
            'telegram_user_id' => '796',
        ]);
        $conversation = AiConversation::factory()->for($account)->create([
            'telegram_chat_authorization_id' => $authorization->id,
            'user_id' => $owner->id,
            'channel' => 'telegram_owner',
            'profile' => TelegramBotProfile::Owner->value,
            'status' => AiConversation::StatusActive,
        ]);
        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => 'user',
            'content' => 'Дай три варіанти назви.',
            'occurred_at' => now()->subMinute(),
        ]);
        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => 'assistant',
            'content' => $options,
            'occurred_at' => now()->subSeconds(30),
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10131,
            'message' => [
                'message_id' => 201,
                'chat' => ['id' => 576],
                'from' => ['id' => 796, 'username' => 'owner'],
                'text' => $currentText,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '576',
            'direction' => 'outbound',
            'text' => 'Тоді обираємо Skyler Studio.',
        ]);
        $this->assertDatabaseHas('ai_conversation_messages', [
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Тоді обираємо Skyler Studio.',
        ]);
        $this->assertDatabaseMissing('ai_conversation_messages', [
            'ai_conversation_id' => $conversation->id,
            'role' => 'rejected_intent',
            'content' => __('app.telegram_out_of_scope'),
        ]);

        $ollamaRequests = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => str_contains($request->url(), 'ollama.com/api/chat'))
            ->values();

        $this->assertCount(1, $ollamaRequests);
        $requestMessages = $ollamaRequests->sole()->data()['messages'];
        $requestText = implode("\n", array_column($requestMessages, 'content'));
        $this->assertTrue(collect($requestMessages)->contains(
            fn (array $message): bool => $message['role'] === 'assistant' && $message['content'] === $options,
        ));
        $this->assertSame(1, substr_count($requestText, $currentText));
    }

    public function test_authorized_owner_ai_status_message_is_edited_through_processing_stages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'disposition' => 'answer',
                        'answer' => "**AI answer** for studio schedule.\n* First item",
                        'follow_up_actions' => [],
                        'action' => null,
                        'reason' => 'studio schedule question',
                    ]),
                ],
            ]),
            'api.telegram.org/*/sendMessage' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9001]]),
            'api.telegram.org/*/sendChatAction' => Http::response(['ok' => true]),
            'api.telegram.org/*/editMessageText' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9001]])
                ->push(['ok' => true, 'result' => ['message_id' => 9001]])
                ->push(['ok' => true, 'result' => ['message_id' => 9001]])
                ->push(['ok' => true, 'result' => ['message_id' => 9001]]),
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
            'telegram_chat_id' => '571',
            'telegram_user_id' => '791',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 10129,
            'message' => [
                'message_id' => 199,
                'chat' => ['id' => 571],
                'from' => ['id' => 791, 'username' => 'owner'],
                'text' => 'How many classes today?',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $telegramRequests = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/'))
            ->values();

        $this->assertSame('sendMessage', Str::afterLast($telegramRequests->get(0)->url(), '/'));
        $this->assertSame(__('app.assistant_status_thinking'), $telegramRequests->get(0)['text']);
        $this->assertSame('sendChatAction', Str::afterLast($telegramRequests->get(1)->url(), '/'));

        $editTexts = $telegramRequests
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'))
            ->map(fn (Request $request): string => (string) $request['text'])
            ->values()
            ->all();

        $this->assertSame([
            __('app.assistant_status_checking_database'),
            __('app.assistant_status_checking_request'),
            __('app.assistant_status_thinking'),
            "<b>AI answer</b> for studio schedule.\n&#8226; First item",
        ], $editTexts);

        $requestEvents = collect(Http::recorded())
            ->map(function (array $record): array {
                $request = $record[0];

                return [
                    'method' => str_contains($request->url(), 'ollama.com/api/chat')
                        ? 'ollama_chat'
                        : Str::afterLast($request->url(), '/'),
                    'text' => (string) data_get($request->data(), 'text', ''),
                ];
            })
            ->values();
        $checkingRequestIndex = $requestEvents->search(fn (array $event): bool => $event['method'] === 'editMessageText' && $event['text'] === __('app.assistant_status_checking_request'));
        $thinkingIndex = $requestEvents->search(fn (array $event): bool => $event['method'] === 'editMessageText' && $event['text'] === __('app.assistant_status_thinking'));
        $llmIndex = $requestEvents->search(fn (array $event): bool => $event['method'] === 'ollama_chat');

        $this->assertSame('sendChatAction', $requestEvents->get($checkingRequestIndex + 1)['method']);
        $this->assertSame('sendChatAction', $requestEvents->get($thinkingIndex + 1)['method']);
        $this->assertGreaterThan($thinkingIndex + 1, $llmIndex);
        $this->assertSame(1, $requestEvents->where('method', 'ollama_chat')->count());

        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '571',
            'direction' => 'outbound',
            'telegram_message_id' => '9001',
            'text' => "**AI answer** for studio schedule.\n* First item",
        ]);

        Carbon::setTestNow();
    }

    public function test_owner_ai_follow_up_actions_are_sent_as_inline_buttons_and_callbacks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'disposition' => 'answer',
                            'answer' => 'Choose a next step.',
                            'follow_up_actions' => [
                                'How many classes today?',
                                'Show studio profile',
                            ],
                            'action' => null,
                            'reason' => 'studio schedule question',
                        ]),
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'disposition' => 'answer',
                            'answer' => __('app.telegram_class_count_for_day', ['date' => '2026-06-28', 'count' => 0]),
                            'follow_up_actions' => [],
                            'action' => null,
                            'reason' => 'studio class count follow-up',
                        ]),
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
            'telegram_chat_id' => '564',
            'telegram_user_id' => '784',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1013,
            'message' => [
                'message_id' => 20,
                'chat' => ['id' => 564],
                'from' => ['id' => 784, 'username' => 'owner'],
                'text' => 'What should I check next?',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $assistantMessage = AiConversationMessage::where('content', 'Choose a next step.')->firstOrFail();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '564'
            && $request['text'] === 'Choose a next step.'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'How many classes today?'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'tg_follow:'.$assistantMessage->id.':0');

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1014,
            'callback_query' => [
                'id' => 'callback-follow-1',
                'from' => ['id' => 784, 'username' => 'owner'],
                'message' => [
                    'message_id' => 21,
                    'chat' => ['id' => 564],
                ],
                'data' => 'tg_follow:'.$assistantMessage->id.':0',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '564',
            'direction' => 'inbound',
            'message_type' => 'callback_query',
            'text' => 'How many classes today?',
        ]);
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '564',
            'direction' => 'outbound',
            'text' => __('app.telegram_class_count_for_day', ['date' => '2026-06-28', 'count' => 0]),
        ]);

        Carbon::setTestNow();
    }

    public function test_owner_pending_action_callback_from_different_sender_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Mail::fake();

        $owner = User::factory()->create(['phone' => '+380671112233']);
        $account = Account::factory()->create(['country_code' => 'UA']);
        $account->addOwner($owner);
        [$installation, $webhookKey] = $this->ownerInstallation();

        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $trainer = Trainer::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
                'capacity' => 8,
                'title' => 'Pole Beginner',
            ]);
        $customer = Customer::factory()->for($account)->create();

        $authorization = TelegramChatAuthorization::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'user_id' => $owner->id,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => '575',
            'telegram_user_id' => '795',
        ]);
        $conversation = AiConversation::factory()->for($account)->create([
            'telegram_chat_authorization_id' => $authorization->id,
            'user_id' => $owner->id,
            'channel' => 'telegram_owner',
            'profile' => TelegramBotProfile::Owner->value,
        ]);
        $action = AiPendingAction::factory()
            ->for($account)
            ->for($conversation, 'conversation')
            ->for($owner, 'user')
            ->create([
                'action_name' => 'create-booking',
                'arguments' => [
                    'schedule_kind' => ScheduleKind::GroupClass->value,
                    'customer_id' => $customer->id,
                    'scheduled_class_id' => $scheduledClass->id,
                ],
                'status' => AiPendingAction::StatusPending,
            ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1017,
            'callback_query' => [
                'id' => 'callback-action-wrong-sender',
                'from' => ['id' => 999, 'username' => 'not-owner'],
                'message' => [
                    'message_id' => 24,
                    'chat' => ['id' => 575],
                ],
                'data' => 'tg_action:confirm:'.$action->id,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertSame(AiPendingAction::StatusPending, $action->refresh()->status);
        $this->assertFalse(ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->exists());
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '575',
            'direction' => 'outbound',
            'text' => __('app.telegram_authorization_failed'),
        ]);

        Carbon::setTestNow();
    }

    public function test_owner_pending_action_inline_confirm_executes_booking_action(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        Mail::fake();

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

        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $trainer = Trainer::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
                'capacity' => 8,
                'title' => 'Pole Beginner',
            ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Аліна Тестова']);
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'disposition' => 'start_booking',
                        'answer' => null,
                        'follow_up_actions' => [],
                        'action' => [
                            'customer_id' => $customer->id,
                            'scheduled_class_id' => $scheduledClass->id,
                        ],
                        'reason' => 'direct booking request',
                    ]),
                ],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        TelegramChatAuthorization::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'user_id' => $owner->id,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => '565',
            'telegram_user_id' => '785',
        ]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1015,
            'message' => [
                'message_id' => 22,
                'chat' => ['id' => 565],
                'from' => ['id' => 785, 'username' => 'owner'],
                'text' => "book customer #{$customer->id} class #{$scheduledClass->id}",
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $action = AiPendingAction::where('action_name', 'create-booking')->firstOrFail();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '565'
            && $request['text'] === __('app.assistant_pending_action_created')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'tg_action:confirm:'.$action->id
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.1.callback_data') === 'tg_action:cancel:'.$action->id);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1016,
            'callback_query' => [
                'id' => 'callback-action-1',
                'from' => ['id' => 785, 'username' => 'owner'],
                'message' => [
                    'message_id' => 23,
                    'chat' => ['id' => 565],
                ],
                'data' => 'tg_action:confirm:'.$action->id,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();

        $this->assertSame($scheduledClass->id, $booking->scheduled_class_id);
        $this->assertSame(AiPendingAction::StatusExecuted, $action->refresh()->status);
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '565',
            'direction' => 'outbound',
            'text' => __('app.assistant_booking_created', ['id' => $booking->id]),
        ]);

        Carbon::setTestNow();
    }

    /**
     * @return array{TelegramBotInstallation, string}
     */
    private function ownerInstallation(): array
    {
        $webhookKey = 'tg_'.Str::random(24);
        $webhookSecret = Str::random(32);

        $installation = TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('scope_id', 0)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->first();

        $attributes = [
            'account_id' => null,
            'scope_type' => 'platform',
            'scope_id' => 0,
            'profile' => TelegramBotProfile::Owner->value,
            'encrypted_webhook_key' => $webhookKey,
            'webhook_key_hash' => TelegramBotInstallation::hashWebhookSecret($webhookKey),
            'encrypted_webhook_secret' => $webhookSecret,
            'webhook_secret_token_hash' => TelegramBotInstallation::hashWebhookSecret($webhookSecret),
            'status' => 'configured',
            'is_enabled' => true,
        ];

        if ($installation) {
            $installation->forceFill($attributes)->save();
            $installation->refresh();
        } else {
            $installation = TelegramBotInstallation::factory()->platformOwner()->create($attributes);
        }

        return [$installation, $webhookKey];
    }
}
