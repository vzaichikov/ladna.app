<?php

namespace Tests\Feature;

use App\Enums\AiConversationMessageRole;
use App\Enums\AiProvider;
use App\Enums\ScheduleKind;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramUpdateStatus;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiConversationMessageAttachment;
use App\Models\AiPendingAction;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\McpToolInvocation;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\TelegramAuthorizationSelectionCandidate;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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

    public function test_unauthorized_owner_photo_does_not_download_before_chat_authorization(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        [$installation, $webhookKey] = $this->ownerInstallation();

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1101,
            'message' => [
                'message_id' => 201,
                'chat' => ['id' => 580, 'type' => 'private'],
                'from' => ['id' => 800, 'username' => 'owner'],
                'photo' => [[
                    'file_id' => 'unauthorized-photo',
                    'file_unique_id' => 'unauthorized-photo-unique',
                    'width' => 800,
                    'height' => 600,
                    'file_size' => 1024,
                ]],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/getFile'));
        $this->assertFalse(AiConversationMessageAttachment::query()->exists());
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_chat_id' => '580',
            'direction' => 'outbound',
            'text' => __('app.telegram_share_contact_to_authorize'),
        ]);
    }

    public function test_authorized_owner_photo_is_rejected_before_download_or_ai_inference_for_ollama(): void
    {
        Storage::fake('local');
        $imageContents = $this->pngImageContents();
        [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('581', '801');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1102,
            'message' => [
                'message_id' => 202,
                'chat' => ['id' => 581, 'type' => 'private'],
                'from' => ['id' => 801, 'username' => 'owner'],
                'caption' => 'What is shown in this image?',
                'photo' => [
                    [
                        'file_id' => 'small-photo',
                        'file_unique_id' => 'small-photo-unique',
                        'width' => 320,
                        'height' => 240,
                        'file_size' => strlen($imageContents),
                    ],
                    [
                        'file_id' => 'large-photo',
                        'file_unique_id' => 'large-photo-unique',
                        'width' => 1280,
                        'height' => 960,
                        'file_size' => strlen($imageContents),
                    ],
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $processedUpdate = TelegramUpdate::query()->where('update_id', 1102)->firstOrFail();
        $this->assertSame(
            TelegramUpdateStatus::Processed,
            $processedUpdate->status,
            $processedUpdate->error_message ?? 'Telegram image update failed.',
        );
        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '581',
            'direction' => 'outbound',
            'text' => __('app.assistant_image_provider_unsupported'),
        ]);
        $this->assertFalse(AiConversationMessage::query()->where('account_id', $account->id)->exists());
        $this->assertFalse(AiConversationMessageAttachment::query()->where('account_id', $account->id)->exists());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/getFile'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ollama.com/'));
    }

    public function test_authorized_owner_photo_uses_one_openai_multimodal_request(): void
    {
        Storage::fake('local');
        $imageContents = $this->pngImageContents();
        [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('591', '811');
        PlatformAiSetting::current()->forceFill([
            'active_provider' => AiProvider::OpenAiApiKey->value,
            'active_model' => 'gpt-5.5',
        ])->save();
        PlatformAiProviderCredential::query()->delete();
        PlatformAiProviderCredential::factory()->create([
            'provider' => AiProvider::OpenAiApiKey->value,
            'model' => 'gpt-5.5',
            'credentials' => ['api_key' => 'test-openai-key'],
            'is_configured' => true,
        ]);
        $assistantEnvelope = [
            'disposition' => 'answer',
            'answer' => 'На скріншоті видно абонемент клієнта.',
            'follow_up_actions' => [],
            'action' => null,
            'calendar_reference' => null,
            'reason' => 'visual question',
            'visual_context' => 'A customer class-pass screen is visible.',
        ];

        Http::fake(function (Request $request) use ($imageContents, $assistantEnvelope) {
            if (str_contains($request->url(), '/getFile')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'file_path' => 'photos/openai-test-image.png',
                        'file_size' => strlen($imageContents),
                    ],
                ]);
            }

            if (str_contains($request->url(), '/file/bot')) {
                return Http::response($imageContents, 200, [
                    'Content-Type' => 'image/png',
                    'Content-Length' => (string) strlen($imageContents),
                ]);
            }

            if (str_ends_with($request->url(), '/v1/responses')) {
                return Http::response([
                    'id' => 'resp_telegram_image',
                    'status' => 'completed',
                    'model' => 'gpt-5.5-2026-04-23',
                    'output' => [[
                        'id' => 'msg_telegram_image',
                        'type' => 'message',
                        'role' => 'assistant',
                        'status' => 'completed',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => json_encode($assistantEnvelope),
                            'annotations' => [],
                        ]],
                    ]],
                ]);
            }

            if (str_contains($request->url(), 'api.telegram.org/')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['message_id' => 901],
                ]);
            }

            return Http::response([], 404);
        });

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1114,
            'message' => [
                'message_id' => 214,
                'chat' => ['id' => 591, 'type' => 'private'],
                'from' => ['id' => 811, 'username' => 'owner'],
                'photo' => [[
                    'file_id' => 'openai-photo',
                    'file_unique_id' => 'openai-photo-unique',
                    'width' => 1280,
                    'height' => 960,
                    'file_size' => strlen($imageContents),
                ]],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '591',
            'direction' => 'outbound',
            'text' => 'На скріншоті видно абонемент клієнта.',
        ]);
        $this->assertTrue(AiConversationMessageAttachment::query()
            ->where('account_id', $account->id)
            ->where('mime_type', 'image/jpeg')
            ->exists());

        $openAiRequests = collect(Http::recorded())
            ->pluck(0)
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/v1/responses'))
            ->values();

        $this->assertCount(1, $openAiRequests);
        $request = $openAiRequests->sole();
        $this->assertSame('gpt-5.5', $request['model']);
        $this->assertFalse($request['store']);
        $this->assertNotEmpty($request['tools']);
        $this->assertSame('ladna_studio_assistant_v3', data_get($request->data(), 'text.format.name'));
        $userContent = collect($request['input'] ?? [])
            ->flatMap(fn (array $message): array => is_array($message['content'] ?? null)
                ? $message['content']
                : []);
        $imageInput = $userContent->firstWhere('type', 'input_image');
        $textInput = $userContent->firstWhere('type', 'input_text');
        $this->assertIsArray($imageInput);
        $this->assertIsArray($textInput);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $imageInput['image_url']);
        $this->assertSame('original', $imageInput['detail']);
        $this->assertStringContainsString(
            'The current owner message has no text. This image-only request must be answered in Ukrainian only.',
            $textInput['text'],
        );
    }

    public function test_telegram_screenshot_can_drive_authoritative_trial_eligibility_investigation(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Europe/Kyiv'));

        try {
            $imageContents = $this->pngImageContents();
            [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('592', '812');
            $customer = Customer::factory()->for($account)->create(['name' => 'Anna Trial']);
            ClassPassPlan::factory()->for($account)->create([
                'name' => 'Trial 250',
                'is_trial' => true,
                'price_cents' => 25000,
                'currency' => 'UAH',
            ]);
            PlatformAiSetting::current()->forceFill([
                'active_provider' => AiProvider::OpenAiApiKey->value,
                'active_model' => 'gpt-5.5',
            ])->save();
            PlatformAiProviderCredential::query()->delete();
            PlatformAiProviderCredential::factory()->create([
                'provider' => AiProvider::OpenAiApiKey->value,
                'model' => 'gpt-5.5',
                'credentials' => ['api_key' => 'test-openai-key'],
                'is_configured' => true,
            ]);
            $openAiCall = 0;

            Http::fake(function (Request $request) use ($imageContents, $customer, &$openAiCall) {
                if (str_contains($request->url(), '/getFile')) {
                    return Http::response([
                        'ok' => true,
                        'result' => [
                            'file_path' => 'photos/trial-investigation.png',
                            'file_size' => strlen($imageContents),
                        ],
                    ]);
                }

                if (str_contains($request->url(), '/file/bot')) {
                    return Http::response($imageContents, 200, [
                        'Content-Type' => 'image/png',
                        'Content-Length' => (string) strlen($imageContents),
                    ]);
                }

                if (str_ends_with($request->url(), '/v1/responses')) {
                    $openAiCall++;

                    if ($openAiCall === 1) {
                        return Http::response([
                            'status' => 'completed',
                            'output' => [[
                                'id' => 'fc_customer_search',
                                'type' => 'function_call',
                                'status' => 'completed',
                                'call_id' => 'call_customer_search',
                                'name' => 'search_customers',
                                'arguments' => '{"query":"Anna Trial"}',
                            ]],
                        ]);
                    }

                    if ($openAiCall === 2) {
                        return Http::response([
                            'status' => 'completed',
                            'output' => [[
                                'id' => 'fc_trial_ledger',
                                'type' => 'function_call',
                                'status' => 'completed',
                                'call_id' => 'call_trial_ledger',
                                'name' => 'investigate_customer_booking_ledger',
                                'arguments' => json_encode([
                                    'customer_id' => $customer->id,
                                    'as_of' => '2026-07-29T12:00:00+03:00',
                                    'source' => 'manual',
                                ]),
                            ]],
                        ]);
                    }

                    return Http::response([
                        'status' => 'completed',
                        'output' => [[
                            'id' => 'msg_trial_answer',
                            'type' => 'message',
                            'role' => 'assistant',
                            'status' => 'completed',
                            'content' => [[
                                'type' => 'output_text',
                                'text' => json_encode([
                                    'disposition' => 'answer',
                                    'answer' => 'Перевірено: пробний абонемент доступний, бо в клієнтки немає записів.',
                                    'follow_up_actions' => [],
                                    'action' => null,
                                    'calendar_reference' => null,
                                    'reason' => 'Deterministic trial eligibility evidence.',
                                    'visual_context' => 'The screenshot identifies Anna Trial and a trial-pass question.',
                                ], JSON_UNESCAPED_UNICODE),
                                'annotations' => [],
                            ]],
                        ]],
                    ]);
                }

                if (str_contains($request->url(), 'api.telegram.org/')) {
                    return Http::response([
                        'ok' => true,
                        'result' => ['message_id' => 902],
                    ]);
                }

                return Http::response([], 404);
            });

            $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
                'update_id' => 1115,
                'message' => [
                    'message_id' => 215,
                    'chat' => ['id' => 592, 'type' => 'private'],
                    'from' => ['id' => 812, 'username' => 'owner'],
                    'caption' => 'Перевір, чому Anna Trial не дає пробний абонемент на перше відвідування.',
                    'photo' => [[
                        'file_id' => 'trial-investigation-photo',
                        'file_unique_id' => 'trial-investigation-photo-unique',
                        'width' => 1280,
                        'height' => 960,
                        'file_size' => strlen($imageContents),
                    ]],
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
            ])->assertNoContent();

            $this->assertDatabaseHas('telegram_messages', [
                'account_id' => $account->id,
                'telegram_chat_id' => '592',
                'direction' => 'outbound',
                'text' => 'Перевірено: пробний абонемент доступний, бо в клієнтки немає записів.',
            ]);
            $this->assertSame(2, McpToolInvocation::query()
                ->whereBelongsTo($account)
                ->whereNull('account_api_token_id')
                ->where('status', 'succeeded')
                ->count());

            $openAiRequests = collect(Http::recorded())
                ->pluck(0)
                ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/v1/responses'))
                ->values();
            $this->assertCount(3, $openAiRequests);
            $this->assertTrue($this->requestIncludesInputImage($openAiRequests[0]));
            $ledgerOutput = collect($openAiRequests[2]['input'] ?? [])->first(
                fn (mixed $item): bool => is_array($item)
                    && ($item['type'] ?? null) === 'function_call_output'
                    && ($item['call_id'] ?? null) === 'call_trial_ledger',
            );
            $this->assertIsArray($ledgerOutput);
            $this->assertStringContainsString(
                '"status":"eligible"',
                (string) ($ledgerOutput['output'] ?? ''),
            );
            $this->assertStringContainsString(
                '"reason_codes":["no_existing_bookings"]',
                (string) ($ledgerOutput['output'] ?? ''),
            );
            $this->assertStringContainsString(
                '"manual_override":{"status":"unavailable","available":false',
                (string) ($ledgerOutput['output'] ?? ''),
            );
            $this->assertStringContainsString(
                '"actor_has_required_permissions":true',
                (string) ($ledgerOutput['output'] ?? ''),
            );
            $this->assertStringNotContainsString(
                '_cents',
                (string) ($ledgerOutput['output'] ?? ''),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_telegram_openai_replaces_raw_image_with_visual_memory_after_two_follow_ups(): void
    {
        Storage::fake('local');
        $imageContents = $this->pngImageContents();
        [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('592', '812');
        PlatformAiSetting::current()->forceFill([
            'active_provider' => AiProvider::OpenAiApiKey->value,
            'active_model' => 'gpt-5.5',
        ])->save();
        PlatformAiProviderCredential::query()->delete();
        PlatformAiProviderCredential::factory()->create([
            'provider' => AiProvider::OpenAiApiKey->value,
            'model' => 'gpt-5.5',
            'credentials' => ['api_key' => 'test-openai-key'],
            'is_configured' => true,
        ]);
        $assistantEnvelopes = [
            [
                'disposition' => 'answer',
                'answer' => 'На скріншоті абонемент Марії.',
                'follow_up_actions' => [],
                'action' => null,
                'calendar_reference' => null,
                'reason' => 'direct image inspection',
                'visual_context' => 'Class-pass screen: Maria, valid through 15 August.',
            ],
            [
                'disposition' => 'answer',
                'answer' => 'До 15 серпня.',
                'follow_up_actions' => [],
                'action' => null,
                'calendar_reference' => null,
                'reason' => 'visual follow-up',
                'visual_context' => 'Class-pass screen: Maria, valid through 15 August.',
            ],
            [
                'disposition' => 'answer',
                'answer' => 'На скріншоті вказана Марія.',
                'follow_up_actions' => [],
                'action' => null,
                'calendar_reference' => null,
                'reason' => 'visual follow-up',
                'visual_context' => 'Class-pass screen: Maria, valid through 15 August.',
            ],
            [
                'disposition' => 'answer',
                'answer' => 'Завтра працюють двоє тренерів.',
                'follow_up_actions' => [],
                'action' => null,
                'calendar_reference' => null,
                'reason' => 'studio context',
                'visual_context' => null,
            ],
        ];
        $openAiResponseIndex = 0;

        Http::fake(function (Request $request) use (
            $imageContents,
            $assistantEnvelopes,
            &$openAiResponseIndex,
        ) {
            if (str_contains($request->url(), '/getFile')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'file_path' => 'photos/openai-memory-test.png',
                        'file_size' => strlen($imageContents),
                    ],
                ]);
            }

            if (str_contains($request->url(), '/file/bot')) {
                return Http::response($imageContents, 200, [
                    'Content-Type' => 'image/png',
                    'Content-Length' => (string) strlen($imageContents),
                ]);
            }

            if (str_ends_with($request->url(), '/v1/responses')) {
                $envelope = $assistantEnvelopes[$openAiResponseIndex++];

                return Http::response([
                    'id' => 'resp_telegram_memory_'.$openAiResponseIndex,
                    'status' => 'completed',
                    'output' => [[
                        'id' => 'msg_telegram_memory_'.$openAiResponseIndex,
                        'type' => 'message',
                        'role' => 'assistant',
                        'status' => 'completed',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => json_encode($envelope),
                        ]],
                    ]],
                ]);
            }

            if (str_contains($request->url(), 'api.telegram.org/')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['message_id' => 902 + $openAiResponseIndex],
                ]);
            }

            return Http::response([], 404);
        });

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ];
        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1115,
            'message' => [
                'message_id' => 215,
                'chat' => ['id' => 592, 'type' => 'private'],
                'from' => ['id' => 812, 'username' => 'owner'],
                'photo' => [[
                    'file_id' => 'openai-memory-photo',
                    'file_unique_id' => 'openai-memory-photo-unique',
                    'width' => 1280,
                    'height' => 960,
                    'file_size' => strlen($imageContents),
                ]],
            ],
        ], $headers)->assertNoContent();

        foreach ([
            216 => 'А до якого числа він діє?',
            217 => 'І чиє імʼя там вказане?',
        ] as $messageId => $messageText) {
            $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
                'update_id' => 900 + $messageId,
                'message' => [
                    'message_id' => $messageId,
                    'chat' => ['id' => 592, 'type' => 'private'],
                    'from' => ['id' => 812, 'username' => 'owner'],
                    'text' => $messageText,
                ],
            ], $headers)->assertNoContent();
        }

        $attachment = AiConversationMessageAttachment::query()
            ->where('account_id', $account->id)
            ->firstOrFail();
        Storage::disk($attachment->disk)->delete($attachment->path);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1118,
            'message' => [
                'message_id' => 218,
                'chat' => ['id' => 592, 'type' => 'private'],
                'from' => ['id' => 812, 'username' => 'owner'],
                'text' => 'Хто з тренерів працює завтра?',
            ],
        ], $headers)->assertNoContent();

        $openAiRequests = collect(Http::recorded())
            ->pluck(0)
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/v1/responses'))
            ->values();

        $this->assertCount(4, $openAiRequests);

        foreach ([0, 1, 2] as $requestIndex) {
            $this->assertTrue($this->requestIncludesInputImage($openAiRequests[$requestIndex]));
        }

        $this->assertFalse($this->requestIncludesInputImage($openAiRequests[3]));
        $finalInputText = collect($openAiRequests[3]['input'] ?? [])
            ->where('role', 'user')
            ->pluck('content')
            ->filter(fn (mixed $content): bool => is_string($content))
            ->implode("\n");
        $this->assertStringContainsString(
            'Class-pass screen: Maria, valid through 15 August.',
            $finalInputText,
        );
    }

    public function test_authorized_owner_image_document_is_rejected_before_download_for_ollama(): void
    {
        Storage::fake('local');
        $imageContents = $this->pngImageContents();
        [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('585', '805');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1108,
            'message' => [
                'message_id' => 208,
                'chat' => ['id' => 585, 'type' => 'private'],
                'from' => ['id' => 805, 'username' => 'owner'],
                'caption' => 'Read this image document.',
                'document' => [
                    'file_id' => 'image-document',
                    'file_unique_id' => 'image-document-unique',
                    'file_name' => 'evidence.png',
                    'mime_type' => 'image/png',
                    'file_size' => strlen($imageContents),
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '585',
            'direction' => 'outbound',
            'text' => __('app.assistant_image_provider_unsupported'),
        ]);
        $this->assertFalse(AiConversationMessageAttachment::query()->where('account_id', $account->id)->exists());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/getFile'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ollama.com/'));
    }

    public function test_owner_image_provider_policy_precedes_get_file_failure(): void
    {
        [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('586', '806');
        Http::fake(['api.telegram.org/*' => Http::response([], 500)]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1109,
            'message' => [
                'message_id' => 209,
                'chat' => ['id' => 586, 'type' => 'private'],
                'from' => ['id' => 806, 'username' => 'owner'],
                'photo' => [[
                    'file_id' => 'unavailable-photo',
                    'file_unique_id' => 'unavailable-photo-unique',
                    'width' => 800,
                    'height' => 600,
                    'file_size' => 1024,
                ]],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '586',
            'direction' => 'outbound',
            'text' => __('app.assistant_image_provider_unsupported'),
        ]);
        $this->assertFalse(AiConversationMessageAttachment::query()->where('account_id', $account->id)->exists());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/getFile'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ollama.com/'));
    }

    public function test_owner_non_image_document_is_rejected_without_download(): void
    {
        [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('587', '807');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1110,
            'message' => [
                'message_id' => 210,
                'chat' => ['id' => 587, 'type' => 'private'],
                'from' => ['id' => 807, 'username' => 'owner'],
                'document' => [
                    'file_id' => 'pdf-document',
                    'file_unique_id' => 'pdf-document-unique',
                    'file_name' => 'report.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => 1024,
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '587',
            'direction' => 'outbound',
            'text' => __('app.telegram_image_unsupported'),
        ]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/getFile'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ollama.com/'));
    }

    public function test_owner_image_provider_policy_precedes_image_validation(): void
    {
        Storage::fake('local');
        [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('588', '808');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1111,
            'message' => [
                'message_id' => 211,
                'chat' => ['id' => 588, 'type' => 'private'],
                'from' => ['id' => 808, 'username' => 'owner'],
                'caption' => 'Please inspect this.',
                'photo' => [[
                    'file_id' => 'corrupt-photo',
                    'file_unique_id' => 'corrupt-photo-unique',
                    'width' => 800,
                    'height' => 600,
                    'file_size' => 12,
                ]],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '588',
            'direction' => 'outbound',
            'text' => __('app.assistant_image_provider_unsupported'),
        ]);
        $this->assertFalse(AiConversationMessage::query()
            ->where('account_id', $account->id)
            ->where('content', 'Please inspect this.')
            ->exists());
        $this->assertFalse(AiConversationMessageAttachment::query()->where('account_id', $account->id)->exists());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ollama.com/'));
    }

    public function test_disabled_ollama_image_input_does_not_plan_a_mutation(): void
    {
        Storage::fake('local');
        $imageContents = $this->pngImageContents();
        [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('589', '809');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1112,
            'message' => [
                'message_id' => 212,
                'chat' => ['id' => 589, 'type' => 'private'],
                'from' => ['id' => 809, 'username' => 'owner'],
                'photo' => [[
                    'file_id' => 'image-only-action',
                    'file_unique_id' => 'image-only-action-unique',
                    'width' => 800,
                    'height' => 600,
                    'file_size' => strlen($imageContents),
                ]],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '589',
            'direction' => 'outbound',
            'text' => __('app.assistant_image_provider_unsupported'),
        ]);
        $this->assertFalse(AiPendingAction::query()->where('account_id', $account->id)->exists());
        $this->assertFalse(AiConversationMessage::query()->where('account_id', $account->id)->exists());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/getFile'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ollama.com/'));
    }

    public function test_owner_photo_album_gets_one_reply_and_is_not_downloaded(): void
    {
        [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('582', '802');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        foreach ([1103, 1104] as $index => $updateId) {
            $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
                'update_id' => $updateId,
                'message' => [
                    'message_id' => 203 + $index,
                    'media_group_id' => 'album-582',
                    'chat' => ['id' => 582, 'type' => 'private'],
                    'from' => ['id' => 802, 'username' => 'owner'],
                    'photo' => [[
                        'file_id' => 'album-photo-'.$index,
                        'file_unique_id' => 'album-photo-unique-'.$index,
                        'width' => 800,
                        'height' => 600,
                        'file_size' => 1024,
                    ]],
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
            ])->assertNoContent();
        }

        $this->assertSame(1, TelegramMessage::query()
            ->where('account_id', $account->id)
            ->where('telegram_chat_id', '582')
            ->where('direction', 'outbound')
            ->where('text', __('app.assistant_image_provider_unsupported'))
            ->count());
        $this->assertFalse(AiConversationMessageAttachment::query()->where('account_id', $account->id)->exists());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/getFile'));
    }

    public function test_owner_oversized_photo_is_rejected_before_get_file(): void
    {
        [$account, $installation, $webhookKey] = $this->authorizedOwnerImageChat('583', '803');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1105,
            'message' => [
                'message_id' => 205,
                'chat' => ['id' => 583, 'type' => 'private'],
                'from' => ['id' => 803, 'username' => 'owner'],
                'photo' => [[
                    'file_id' => 'oversized-photo',
                    'file_unique_id' => 'oversized-photo-unique',
                    'width' => 2000,
                    'height' => 1500,
                    'file_size' => (2 * 1024 * 1024) + 1,
                ]],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '583',
            'direction' => 'outbound',
            'text' => __('app.assistant_image_provider_unsupported'),
        ]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/getFile'));
    }

    public function test_restart_removes_telegram_image_attachment_and_private_file(): void
    {
        Storage::fake('local');
        $imageContents = $this->pngImageContents();
        [$account, $installation, $webhookKey, $authorization] = $this->authorizedOwnerImageChat('584', '804');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $conversation = AiConversation::factory()->for($account)->create([
            'telegram_chat_authorization_id' => $authorization->id,
            'user_id' => $authorization->user_id,
            'channel' => 'telegram_owner',
            'profile' => TelegramBotProfile::Owner->value,
            'status' => AiConversation::StatusActive,
        ]);
        $message = AiConversationMessage::factory()
            ->for($account)
            ->for($conversation, 'conversation')
            ->create();
        $attachment = AiConversationMessageAttachment::factory()
            ->for($account)
            ->for($message, 'message')
            ->create();
        Storage::disk('local')->put($attachment->path, $imageContents);
        Storage::disk('local')->assertExists($attachment->path);

        $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
            'update_id' => 1107,
            'message' => [
                'message_id' => 207,
                'chat' => ['id' => 584, 'type' => 'private'],
                'from' => ['id' => 804, 'username' => 'owner'],
                'caption' => '/restart',
                'photo' => [[
                    'file_id' => 'ignored-command-photo',
                    'file_unique_id' => 'ignored-command-photo-unique',
                    'width' => 800,
                    'height' => 600,
                    'file_size' => strlen($imageContents),
                ]],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ])->assertNoContent();

        $this->assertModelMissing($attachment);
        Storage::disk('local')->assertMissing($attachment->path);
        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '584',
            'direction' => 'outbound',
            'text' => __('app.telegram_conversation_restarted'),
        ]);
        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $account->id,
            'telegram_chat_id' => '584',
            'direction' => 'outbound',
            'text' => __('app.telegram_image_command_ignored'),
        ]);
        $this->assertCount(0, Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), '/getFile'),
        ));
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
                    'content' => '{"disposition":"cancel_dialog","answer":null,"follow_up_actions":[],"action":{},"calendar_reference":null,"reason":"owner abandoned active booking dialog"}',
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
                        'calendar_reference' => null,
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
                        'content' => '{"disposition":"start_booking","answer":null,"follow_up_actions":[],"action":{"customer_query":"Аліна Тестова","date":"2026-06-30","use_actor_trainer":true},"calendar_reference":{"date":"2026-06-30","uses_schedule_details":false},"reason":"direct booking request"}',
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"disposition":"continue_booking","answer":null,"follow_up_actions":[],"action":{"option_number":1},"calendar_reference":null,"reason":"selected visible class option"}',
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
                        'answer' => "### Summary\n\n**AI answer** for studio schedule.\n* `B8V2-LJ7L` \$\\rightarrow\$ First item",
                        'follow_up_actions' => [],
                        'action' => null,
                        'calendar_reference' => null,
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
            'text' => "### Summary\n\n**AI answer** for studio schedule.\n* `B8V2-LJ7L` \$\\rightarrow\$ First item",
        ]);
        $this->assertTrue(AiConversationMessage::where('content', "### Summary\n\n**AI answer** for studio schedule.\n* `B8V2-LJ7L` \$\\rightarrow\$ First item")
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
            && $request['text'] === "<b>Summary</b>\n\n<b>AI answer</b> for studio schedule.\n&#8226; <code>B8V2-LJ7L</code> → First item");

        $telegramMethods = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/'))
            ->map(fn (Request $request): string => Str::afterLast($request->url(), '/'))
            ->values();

        $this->assertSame('sendChatAction', $telegramMethods->get($telegramMethods->count() - 2));
        $this->assertSame('sendMessage', $telegramMethods->last());

        Carbon::setTestNow();
    }

    public function test_distracted_owner_weekday_conversation_uses_calendar_anchors_and_keeps_context(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 21:40:00', 'Europe/Kyiv'));

        try {
            Http::fake([
                'ollama.com/api/chat' => Http::sequence()
                    ->push([
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"disposition":"answer","answer":"У Каті завтра є заняття.","follow_up_actions":[],"action":null,"calendar_reference":{"date":"2026-07-24","uses_schedule_details":true},"reason":"tomorrow schedule"}',
                        ],
                    ])
                    ->push([
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"disposition":"answer","answer":"У суботу, 25 липня, є заняття.","follow_up_actions":[],"action":null,"calendar_reference":{"date":"2026-07-25","uses_schedule_details":true},"reason":"Saturday schedule from supplied calendar"}',
                        ],
                    ])
                    ->push([
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"disposition":"answer","answer":"Так, субота — це 25 липня.","follow_up_actions":[],"action":null,"calendar_reference":{"date":"2026-07-25","uses_schedule_details":false},"reason":"confirmed Saturday date"}',
                        ],
                    ]),
                'api.telegram.org/*' => Http::response(['ok' => true]),
            ]);

            $owner = User::factory()->create(['phone' => '+380671112244']);
            $account = Account::factory()->create([
                'name' => 'Skyler owner studio',
                'country_code' => 'UA',
                'timezone' => 'Europe/Kyiv',
            ]);
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
                'telegram_chat_id' => '588',
                'telegram_user_id' => '808',
            ]);

            $ownerMessages = [
                'а шо там в каті зафтра, я шось забула',
                'ой я вже забула шо питала... а в суботу шо там?',
                'стоп то субота 25 чи 26 бо я вже нипоняла 😵‍💫',
            ];

            foreach ($ownerMessages as $index => $ownerMessage) {
                $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
                    'update_id' => 1100 + $index,
                    'message' => [
                        'message_id' => 210 + $index,
                        'chat' => ['id' => 588],
                        'from' => ['id' => 808, 'username' => 'owner'],
                        'text' => $ownerMessage,
                    ],
                ], [
                    'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
                ])->assertNoContent();
            }

            $conversation = AiConversation::query()
                ->whereBelongsTo($account)
                ->where('channel', 'telegram_owner')
                ->firstOrFail();
            $messages = $conversation->messages()->oldest('id')->get();
            $assistantMessages = $messages->where('role', AiConversationMessageRole::Assistant);

            $this->assertCount(6, $messages);
            $this->assertCount(3, $assistantMessages);
            $this->assertSame('У суботу, 25 липня, є заняття.', $assistantMessages->values()->get(1)?->content);
            $this->assertSame('Так, субота — це 25 липня.', $assistantMessages->last()?->content);
            $this->assertSame(
                [
                    'date' => '2026-07-25',
                    'uses_schedule_details' => false,
                ],
                data_get($assistantMessages->last()?->metadata, 'calendar_reference'),
            );
            $this->assertTrue($assistantMessages->every(
                fn (AiConversationMessage $message): bool => data_get($message->metadata, 'used_ai') === true
                    && data_get($message->metadata, 'fallback_reason') === null,
            ));
            $this->assertFalse(AiPendingAction::query()->whereBelongsTo($account)->exists());

            $ollamaRequests = collect(Http::recorded())
                ->map(fn (array $record): Request => $record[0])
                ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/api/chat'))
                ->values();

            $this->assertCount(3, $ollamaRequests);
            $secondRequestText = collect($ollamaRequests->get(1)?->data()['messages'] ?? [])->pluck('content')->implode("\n");
            $lastRequestText = collect($ollamaRequests->last()?->data()['messages'] ?? [])->pluck('content')->implode("\n");

            $this->assertStringContainsString($ownerMessages[0], $secondRequestText);
            $this->assertSame(1, substr_count($secondRequestText, $ownerMessages[1]));
            $this->assertStringContainsString('У суботу, 25 липня, є заняття.', $lastRequestText);
            $this->assertSame(1, substr_count($lastRequestText, $ownerMessages[2]));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_telegram_contextual_option_reply_uses_same_conversation_snapshot_once(): void
    {
        $currentText = 'мені більше подобається третій варіант';
        $options = "Можу запропонувати:\n1. Skyler Flow\n2. Skyler Space\n3. Skyler Studio";
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"answer","answer":"Тоді обираємо Skyler Studio.","follow_up_actions":[],"action":null,"calendar_reference":null,"reason":"contextual selection from prior options"}',
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
                        'calendar_reference' => null,
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

    public function test_authorized_owner_investigation_reuses_transient_telegram_status_updates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 09:00:00', 'Europe/Kyiv'));

        try {
            $owner = User::factory()->create(['phone' => '+380671112244']);
            $account = Account::factory()->create([
                'country_code' => 'UA',
                'timezone' => 'Europe/Kyiv',
            ]);
            $account->addOwner($owner);
            $customer = Customer::factory()->for($account)->create(['name' => 'Investigation Customer']);
            PlatformAiSetting::query()->delete();
            PlatformAiProviderCredential::query()->delete();
            PlatformAiSetting::factory()->create([
                'owner_ai_assistant_enabled' => true,
                'active_provider' => AiProvider::OllamaCloud->value,
                'active_model' => 'gemma4:31b',
            ]);
            PlatformAiProviderCredential::factory()->create([
                'provider' => AiProvider::OllamaCloud->value,
                'model' => 'gemma4:31b',
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
            Http::fake([
                'ollama.com/api/chat' => Http::sequence()
                    ->push([
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'tool_calls' => [[
                                'function' => [
                                    'name' => 'search_customers',
                                    'arguments' => ['query' => 'Investigation Customer'],
                                ],
                            ]],
                        ],
                    ])
                    ->push([
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'tool_calls' => [[
                                'function' => [
                                    'name' => 'investigate_customer_booking_ledger',
                                    'arguments' => ['customer_id' => $customer->id],
                                ],
                            ]],
                        ],
                    ])
                    ->push([
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"disposition":"answer","answer":"Перевірено: невідповідностей не знайдено.","follow_up_actions":[],"action":null,"calendar_reference":null,"reason":"Ledger evidence."}',
                        ],
                    ]),
                'api.telegram.org/*/sendMessage' => Http::response([
                    'ok' => true,
                    'result' => ['message_id' => 9002],
                ]),
                'api.telegram.org/*/sendChatAction' => Http::response(['ok' => true]),
                'api.telegram.org/*/editMessageText' => Http::response([
                    'ok' => true,
                    'result' => ['message_id' => 9002],
                ]),
            ]);

            $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), [
                'update_id' => 10130,
                'message' => [
                    'message_id' => 200,
                    'chat' => ['id' => 572],
                    'from' => ['id' => 792, 'username' => 'owner'],
                    'text' => 'Перевір записи Investigation Customer',
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
            ])->assertNoContent();

            $editTexts = collect(Http::recorded())
                ->map(fn (array $record): Request => $record[0])
                ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'))
                ->map(fn (Request $request): string => (string) $request['text'])
                ->values()
                ->all();

            $this->assertSame([
                __('app.assistant_status_checking_database'),
                __('app.assistant_status_checking_request'),
                __('app.assistant_status_thinking'),
                __('app.assistant_status_searching_customer'),
                __('app.assistant_status_preparing_answer'),
                __('app.assistant_status_checking_bookings'),
                __('app.assistant_status_checking_class_passes'),
                __('app.assistant_status_preparing_answer'),
                'Перевірено: невідповідностей не знайдено.',
            ], $editTexts);
            $this->assertDatabaseHas('telegram_messages', [
                'account_id' => $account->id,
                'telegram_chat_id' => '572',
                'direction' => 'outbound',
                'telegram_message_id' => '9002',
                'text' => 'Перевірено: невідповідностей не знайдено.',
            ]);
            $this->assertFalse(TelegramMessage::query()
                ->whereBelongsTo($account)
                ->whereIn('text', [
                    __('app.assistant_status_searching_customer'),
                    __('app.assistant_status_checking_bookings'),
                    __('app.assistant_status_checking_class_passes'),
                    __('app.assistant_status_preparing_answer'),
                ])
                ->exists());
            $this->assertSame(2, AiConversationMessage::query()->whereBelongsTo($account)->count());
        } finally {
            Carbon::setTestNow();
        }
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
                            'calendar_reference' => null,
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
                            'calendar_reference' => null,
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
                        'calendar_reference' => null,
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
     * @return array{Account, TelegramBotInstallation, string, TelegramChatAuthorization}
     */
    private function authorizedOwnerImageChat(string $chatId, string $telegramUserId): array
    {
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
        $authorization = TelegramChatAuthorization::factory()->for($account)->create([
            'telegram_bot_installation_id' => $installation->id,
            'user_id' => $owner->id,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => $chatId,
            'telegram_user_id' => $telegramUserId,
        ]);

        return [$account, $installation, $webhookKey, $authorization];
    }

    /**
     * @param  array<string, mixed>|null  $assistantEnvelope
     */
    private function fakeTelegramImageAndAi(string $imageContents, ?array $assistantEnvelope = null): void
    {
        $assistantEnvelope ??= [
            'disposition' => 'answer',
            'answer' => 'The image was received.',
            'follow_up_actions' => [],
            'action' => null,
            'calendar_reference' => null,
            'reason' => 'visual question',
        ];

        Http::fake(function (Request $request) use ($imageContents, $assistantEnvelope) {
            if (str_contains($request->url(), '/getFile')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'file_path' => 'photos/test-image.png',
                        'file_size' => strlen($imageContents),
                    ],
                ]);
            }

            if (str_contains($request->url(), '/file/bot')) {
                return Http::response($imageContents, 200, [
                    'Content-Type' => 'image/png',
                    'Content-Length' => (string) strlen($imageContents),
                ]);
            }

            if (str_ends_with($request->url(), '/api/show')) {
                return Http::response(['capabilities' => ['vision']]);
            }

            if (str_ends_with($request->url(), '/api/chat')) {
                $hasImage = collect($request['messages'] ?? [])
                    ->contains(fn (mixed $message): bool => is_array($message)
                        && ($message['images'] ?? []) !== []);

                return Http::response([
                    'message' => [
                        'role' => 'assistant',
                        'content' => $hasImage
                            ? 'Exact OCR: class pass details.'
                            : json_encode($assistantEnvelope),
                    ],
                ]);
            }

            if (str_contains($request->url(), 'api.telegram.org/')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['message_id' => 900],
                ]);
            }

            return Http::response([], 404);
        });
    }

    private function requestIncludesInputImage(Request $request): bool
    {
        return collect($request['input'] ?? [])
            ->where('role', 'user')
            ->flatMap(fn (array $message): array => is_array($message['content'] ?? null)
                ? $message['content']
                : [])
            ->contains(fn (mixed $content): bool => is_array($content)
                && ($content['type'] ?? null) === 'input_image');
    }

    private function pngImageContents(): string
    {
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 120, 60));
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        $this->assertIsString($contents);

        return $contents;
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
