<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\ScheduleKind;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
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
            ->where('text', __('app.telegram_class_count_for_day', ['date' => '2026-06-28', 'count' => 0]))
            ->exists());
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendChatAction'));

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
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendChatAction'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '563'
            && $request['parse_mode'] === 'HTML'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);

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

        Carbon::setTestNow();
    }

    public function test_owner_booking_dialog_uses_authorized_trainer_and_accepts_class_name_reply(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00', 'Europe/Kiev'));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Mail::fake();

        $owner = User::factory()->create(['phone' => '+380671112233']);
        $account = Account::factory()->create(['country_code' => 'UA', 'timezone' => 'Europe/Kiev']);
        $account->addOwner($owner);
        PlatformAiSetting::query()->delete();
        PlatformAiSetting::factory()->create([
            'owner_ai_assistant_enabled' => true,
            'active_provider' => null,
            'active_model' => null,
        ]);
        [$installation, $webhookKey] = $this->ownerInstallation();

        $location = Location::factory()->for($account)->create(['timezone' => 'Europe/Kiev']);
        $room = Room::factory()->for($account)->for($location)->create();
        $trainer = Trainer::factory()->for($account)->create([
            'name' => 'Slastya',
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

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendChatAction'));

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
                        'content' => json_encode([
                            'answer' => "**AI answer** for studio schedule.\n* First item",
                            'follow_up_actions' => [],
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
        $this->assertCount(2, collect(Http::recorded())
            ->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/sendChatAction')));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '558'
            && $request['parse_mode'] === 'HTML'
            && $request['text'] === "<b>AI answer</b> for studio schedule.\n&#8226; First item");

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
                        'content' => '{"in_scope":true,"reason":"studio schedule question"}',
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'answer' => 'Choose a next step.',
                            'follow_up_actions' => [
                                'How many classes today?',
                                'Show studio profile',
                            ],
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

        PlatformAiSetting::query()->firstOrFail()->update([
            'active_provider' => null,
            'active_model' => null,
        ]);

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

    public function test_owner_pending_action_inline_confirm_executes_booking_action(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Mail::fake();

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
