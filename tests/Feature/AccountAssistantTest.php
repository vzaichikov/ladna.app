<?php

namespace Tests\Feature;

use App\Enums\AiConversationMessageRole;
use App\Enums\AiProvider;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiPendingAction;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountAssistantTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dashboard_chat_widget_is_gated_by_global_flag(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        PlatformAiSetting::query()->delete();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertDontSee('data-assistant-chat', false);

        PlatformAiSetting::factory()->create(['owner_ai_assistant_enabled' => true]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee('data-assistant-chat', false);
    }

    public function test_dashboard_message_endpoint_uses_global_ai_and_stores_user_scoped_history(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"answer","answer":"Dashboard AI answer.","follow_up_actions":[],"action":null,"calendar_reference":null,"reason":"studio question"}',
                ],
            ]),
        ]);

        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->configureGlobalOllama();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'How many classes today?',
            ])
            ->assertOk()
            ->assertJsonPath('messages.0.role', AiConversationMessageRole::User->value)
            ->assertJsonPath('messages.1.content', 'Dashboard AI answer.');

        $this->assertDatabaseHas('ai_conversations', [
            'account_id' => $account->id,
            'user_id' => $owner->id,
            'channel' => 'dashboard_chat',
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://ollama.com/api/chat'
                && $request->data()['model'] === 'gemma3:27b-cloud';
        });
    }

    public function test_dashboard_repairs_a_distracted_owner_weekday_mismatch_before_storing_the_answer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 21:40:00', 'Europe/Kyiv'));

        try {
            Http::fake([
                'ollama.com/api/chat' => Http::sequence()
                    ->push([
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"disposition":"answer","answer":"У суботу, 26 липня, є пʼять занять.","follow_up_actions":[],"action":null,"calendar_reference":{"date":"2026-07-26","requested_weekday":"saturday","weekday_occurrence":"first","uses_schedule_details":true},"reason":"incorrect Saturday schedule"}',
                        ],
                    ])
                    ->push([
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"disposition":"answer","answer":"У суботу, 25 липня, є одне заняття.","follow_up_actions":[],"action":null,"calendar_reference":{"date":"2026-07-25","requested_weekday":"saturday","weekday_occurrence":"first","uses_schedule_details":true},"reason":"corrected Saturday schedule"}',
                        ],
                    ]),
            ]);

            $owner = User::factory()->create();
            $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
            $account->addOwner($owner);
            $this->configureGlobalOllama();

            $this->actingAs($owner)
                ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                    'message' => 'а в суботу шо там? бо я вже дні попутала 🙈',
                ])
                ->assertOk()
                ->assertJsonPath('messages.1.content', 'У суботу, 25 липня, є одне заняття.')
                ->assertJsonPath('messages.1.metadata.calendar_reference.date', '2026-07-25')
                ->assertJsonPath('messages.1.metadata.calendar_reference.requested_weekday', 'saturday')
                ->assertJsonPath('messages.1.metadata.calendar_reference.weekday_occurrence', 'first')
                ->assertJsonPath('messages.1.metadata.calendar_reference.uses_schedule_details', true)
                ->assertJsonPath('pending_actions', []);

            Http::assertSentCount(2);
            $this->assertSame(0, AiPendingAction::query()->whereBelongsTo($account)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_message_endpoint_streams_transient_statuses_without_persisting_them(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"answer","answer":"Streamed dashboard answer.","follow_up_actions":[],"action":null,"calendar_reference":null,"reason":"studio question"}',
                ],
            ]),
        ]);

        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->configureGlobalOllama();

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'Check the studio data',
            ], [
                'Accept' => 'application/x-ndjson',
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/x-ndjson; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no');

        $events = collect(explode("\n", trim($response->streamedContent())))
            ->filter()
            ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
            ->values();
        $statusKeys = $events
            ->where('type', 'status')
            ->pluck('key')
            ->all();
        $result = $events->firstWhere('type', 'result');

        $this->assertSame([
            'assistant_status_checking_database',
            'assistant_status_checking_request',
            'assistant_status_thinking',
        ], $statusKeys);
        $this->assertSame('Streamed dashboard answer.', $result['payload']['messages'][1]['content']);
        $this->assertSame(
            [
                AiConversationMessageRole::User->value,
                AiConversationMessageRole::Assistant->value,
            ],
            AiConversationMessage::query()
                ->whereBelongsTo($account)
                ->orderBy('id')
                ->pluck('role')
                ->map(fn (AiConversationMessageRole $role): string => $role->value)
                ->all(),
        );
        $this->assertFalse(AiConversationMessage::query()
            ->whereBelongsTo($account)
            ->whereIn('content', [
                __('app.assistant_status_checking_database'),
                __('app.assistant_status_checking_request'),
                __('app.assistant_status_thinking'),
            ])
            ->exists());
    }

    public function test_readonly_demo_uses_ai_for_readonly_answers_without_preparing_actions(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"answer","answer":"У демо є 6 людей у Лавандовій залі.","follow_up_actions":["Покажи навантаження залів"],"action":null,"calendar_reference":null,"reason":"demo studio question"}',
                ],
            ]),
        ]);

        $owner = User::factory()->create();
        $account = Account::factory()->demoReadonly()->create();
        $account->addOwner($owner);
        $this->configureGlobalOllama();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee('data-assistant-chat', false);

        $response = $this->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
            'message' => 'Запиши Анну на заняття і покажи завантаження залів.',
        ])
            ->assertOk()
            ->assertJsonPath('messages.1.content', 'У демо є 6 людей у Лавандовій залі.')
            ->assertJsonPath('messages.1.metadata.follow_up_actions.0', 'Покажи навантаження залів')
            ->assertJsonPath('pending_actions', []);

        $this->assertSame(0, AiPendingAction::query()->whereBelongsTo($account)->count());
        Http::assertSentCount(1);

        $conversation = AiConversation::query()->whereBelongsTo($account)->sole();
        $action = AiPendingAction::factory()
            ->for($account)
            ->for($conversation, 'conversation')
            ->for($owner, 'user')
            ->create();

        $this->postJson(route('dashboard.accounts.assistant.actions.confirm', [$account, $action]))
            ->assertStatus(423)
            ->assertJsonPath('code', 'demo_readonly');

        $this->assertSame(AiPendingAction::StatusPending, $action->fresh()->status);
        $this->assertNotNull($response->json('messages.1.metadata.provider'));
    }

    public function test_dashboard_message_endpoint_stores_ai_follow_up_actions(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'disposition' => 'answer',
                        'answer' => 'Tomorrow has 4 bookings.',
                        'follow_up_actions' => [
                            'Show trainer load for tomorrow',
                            'Show customers without pass reservations',
                            'Show available spots tomorrow',
                            'This fourth suggestion must be ignored',
                        ],
                        'action' => null,
                        'calendar_reference' => null,
                        'reason' => 'studio analytics question',
                    ]),
                ],
            ]),
        ]);

        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->configureGlobalOllama();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'What bookings are there tomorrow?',
            ])
            ->assertOk()
            ->assertJsonPath('messages.1.content', 'Tomorrow has 4 bookings.')
            ->assertJsonPath('messages.1.metadata.follow_up_actions.0', 'Show trainer load for tomorrow')
            ->assertJsonPath('messages.1.metadata.follow_up_actions.1', 'Show customers without pass reservations')
            ->assertJsonPath('messages.1.metadata.follow_up_actions.2', 'Show available spots tomorrow')
            ->assertJsonMissingPath('messages.1.metadata.follow_up_actions.3');
    }

    public function test_dashboard_message_endpoint_stores_help_sources_without_full_help_text(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"answer","answer":"Відкрийте Клієнти й натисніть Додати клієнта.","follow_up_actions":[],"action":null,"calendar_reference":null,"reason":"Ladna help question"}',
                ],
            ]),
        ]);

        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->configureGlobalOllama();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'Як додати клієнта?',
            ])
            ->assertOk()
            ->assertJsonPath('messages.1.metadata.help_sources.0.slug', 'customers-bookings')
            ->assertJsonPath('messages.1.metadata.help_sources.0.sections.0', 'Як додати клієнта вручну')
            ->assertJsonMissingPath('messages.1.metadata.help_sources.0.fragments');
    }

    public function test_dashboard_contextual_option_reply_reaches_model_once_without_current_message_duplication(): void
    {
        $currentText = 'мені більше подобається третій варіант';
        $options = "Ось три варіанти:\n1. Ladna Flow\n2. Ladna Space\n3. Ladna Studio";
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"answer","answer":"Третій варіант, Ladna Studio, добре підходить.","follow_up_actions":[],"action":null,"calendar_reference":null,"reason":"contextual selection from prior options"}',
                ],
            ]),
        ]);

        $owner = User::factory()->create(['name' => 'Валерія']);
        $account = Account::factory()->create(['name' => 'Skyler owner studio']);
        $account->addOwner($owner);
        $this->configureGlobalOllama();
        $conversation = AiConversation::factory()
            ->for($account)
            ->for($owner, 'user')
            ->create([
                'channel' => 'dashboard_chat',
                'status' => AiConversation::StatusActive,
            ]);
        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::User->value,
            'content' => 'Запропонуй три варіанти назви.',
            'occurred_at' => now()->subMinute(),
        ]);
        $conversation->messages()->create([
            'account_id' => $account->id,
            'role' => AiConversationMessageRole::Assistant->value,
            'content' => $options,
            'occurred_at' => now()->subSeconds(30),
        ]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => $currentText,
            ])
            ->assertOk()
            ->assertJsonPath('messages.3.role', AiConversationMessageRole::Assistant->value)
            ->assertJsonPath('messages.3.content', 'Третій варіант, Ladna Studio, добре підходить.')
            ->assertJsonPath('messages.3.metadata.disposition', 'answer');

        Http::assertSent(function (Request $request) use ($currentText, $options): bool {
            $messages = $request->data()['messages'];
            $contents = array_column($messages, 'content');
            $combined = implode("\n", $contents);
            $priorUserIndex = array_search('Запропонуй три варіанти назви.', $contents, true);
            $optionsIndex = array_search($options, $contents, true);

            return $priorUserIndex !== false
                && $optionsIndex !== false
                && $priorUserIndex < $optionsIndex
                && $optionsIndex < array_key_last($messages)
                && substr_count($combined, $currentText) === 1;
        });
        Http::assertSentCount(1);
    }

    public function test_model_proposed_booking_id_is_validated_against_current_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $otherOwner = User::factory()->create();
        $otherAccount = Account::factory()->create();
        $otherAccount->addOwner($otherOwner);
        $otherBooking = $this->bookingFor($otherAccount, $otherOwner);
        $this->configureGlobalOllama();
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'disposition' => 'cancel_booking',
                        'answer' => null,
                        'follow_up_actions' => [],
                        'action' => ['booking_id' => $otherBooking->id],
                        'calendar_reference' => null,
                        'reason' => 'booking cancellation request',
                    ]),
                ],
            ]),
        ]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'Скасуй цей запис.',
            ])
            ->assertOk()
            ->assertJsonPath('messages.1.content', __('app.assistant_booking_not_found'))
            ->assertJsonPath('pending_actions', []);

        $this->assertSame(0, AiPendingAction::query()->whereBelongsTo($account)->count());
        $this->assertSame(ClassBookingStatus::Booked, $otherBooking->fresh()->status);
    }

    public function test_incomplete_model_action_returns_unavailable_without_pending_action(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"cancel_booking","answer":null,"follow_up_actions":[],"action":{},"calendar_reference":null,"reason":"missing booking id"}',
                ],
            ]),
        ]);

        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->configureGlobalOllama();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'Скасуй його.',
            ])
            ->assertOk()
            ->assertJsonPath('messages.1.content', __('app.assistant_ai_unavailable'))
            ->assertJsonPath('messages.1.metadata.fallback_reason', 'invalid_ai_response')
            ->assertJsonPath('pending_actions', []);

        $this->assertSame(0, AiPendingAction::query()->whereBelongsTo($account)->count());
    }

    public function test_dashboard_chat_can_clear_current_user_conversation(): void
    {
        PlatformAiSetting::query()->delete();
        PlatformAiSetting::factory()->create(['owner_ai_assistant_enabled' => true]);

        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $account->addOwner($otherOwner);

        $conversation = AiConversation::factory()
            ->for($account)
            ->for($owner, 'user')
            ->create([
                'channel' => 'dashboard_chat',
                'status' => AiConversation::StatusActive,
            ]);
        $message = AiConversationMessage::factory()
            ->for($account)
            ->for($conversation, 'conversation')
            ->create([
                'role' => AiConversationMessageRole::User->value,
                'content' => 'Show bookings tomorrow',
            ]);
        $action = AiPendingAction::factory()
            ->for($account)
            ->for($conversation, 'conversation')
            ->for($owner, 'user')
            ->create();
        $otherConversation = AiConversation::factory()
            ->for($account)
            ->for($otherOwner, 'user')
            ->create([
                'channel' => 'dashboard_chat',
                'status' => AiConversation::StatusActive,
            ]);

        $this->actingAs($owner)
            ->deleteJson(route('dashboard.accounts.assistant.destroy', $account))
            ->assertOk()
            ->assertJsonPath('messages', [])
            ->assertJsonPath('pending_actions', []);

        $this->assertSame(AiConversation::StatusCleared, $conversation->refresh()->status);
        $this->assertSame(AiPendingAction::StatusCancelled, $action->refresh()->status);
        $this->assertNotNull($action->cancelled_at);
        $this->assertDatabaseHas('ai_conversation_messages', [
            'id' => $message->id,
            'content' => 'Show bookings tomorrow',
        ]);
        $this->assertDatabaseHas('ai_conversations', [
            'id' => $otherConversation->id,
            'status' => AiConversation::StatusActive,
        ]);
        $this->assertSame(1, AiConversation::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($owner, 'user')
            ->where('channel', 'dashboard_chat')
            ->where('status', AiConversation::StatusActive)
            ->count());
    }

    public function test_dashboard_booking_dialog_resolves_typo_and_requires_class_choice_confirmation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-29 08:00:00', 'UTC'));
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"disposition":"start_booking","answer":null,"follow_up_actions":[],"action":{"customer_query":"Алина Тестовая","trainer_query":"Катя","date":"2026-06-30","use_actor_trainer":false},"calendar_reference":{"date":"2026-06-30","requested_weekday":null,"weekday_occurrence":null,"uses_schedule_details":false},"reason":"direct booking request"}',
                ],
            ]),
        ]);

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $account->addOwner($owner);
        $this->configureGlobalOllama();

        $location = Location::factory()->for($account)->create(['name' => 'Podil']);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Катя']);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Pole Beginner',
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Алина Тестова']);
        $firstClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($trainer)
            ->for($classType)
            ->create([
                'title' => 'Pole Beginner',
                'capacity' => 6,
                'starts_at' => Carbon::parse('2026-06-30 09:00:00', 'Europe/Kyiv')->timezone('UTC'),
                'ends_at' => Carbon::parse('2026-06-30 10:00:00', 'Europe/Kyiv')->timezone('UTC'),
            ]);
        $secondClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($trainer)
            ->for($classType)
            ->create([
                'title' => 'Stretching',
                'capacity' => 5,
                'starts_at' => Carbon::parse('2026-06-30 11:00:00', 'Europe/Kyiv')->timezone('UTC'),
                'ends_at' => Carbon::parse('2026-06-30 12:00:00', 'Europe/Kyiv')->timezone('UTC'),
            ]);

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'Запиши Алину Тестовую на завтра к Кате',
            ])
            ->assertOk()
            ->assertJsonPath('pending_actions', [])
            ->assertJsonPath('messages.1.metadata.booking_dialog.status', 'awaiting_class')
            ->assertJsonPath('messages.1.metadata.booking_dialog.customer_id', $customer->id)
            ->assertJsonPath('messages.1.metadata.booking_dialog.trainer_id', $trainer->id)
            ->assertJsonPath('messages.1.metadata.booking_dialog.class_options.0.id', $firstClass->id)
            ->assertJsonPath('messages.1.metadata.booking_dialog.class_options.1.id', $secondClass->id)
            ->assertJsonPath('messages.1.metadata.follow_up_actions.0', '1')
            ->assertJsonPath('messages.1.metadata.follow_up_actions.1', '2');

        $this->assertStringContainsString('09:00-10:00', $response->json('messages.1.content'));
        $this->assertStringContainsString('11:00-12:00', $response->json('messages.1.content'));
        $this->assertFalse(ClassBooking::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer)
            ->exists());

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => '2',
            ])
            ->assertOk()
            ->assertJsonPath('pending_actions.0.action_name', 'create-booking')
            ->assertJsonPath('pending_actions.0.preview.scheduled_class_id', $secondClass->id)
            ->assertJsonPath('messages.3.metadata.booking_dialog.status', 'pending_action_created');

        $this->assertFalse(ClassBooking::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer)
            ->exists());

        $actionId = $response->json('pending_actions.0.id');

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.actions.confirm', [$account, $actionId]))
            ->assertOk()
            ->assertJsonPath('pending_actions', []);

        $this->assertDatabaseHas('class_bookings', [
            'account_id' => $account->id,
            'customer_id' => $customer->id,
            'scheduled_class_id' => $secondClass->id,
            'status' => ClassBookingStatus::Booked->value,
        ]);

        Carbon::setTestNow();
    }

    public function test_cancel_booking_action_requires_confirmation_before_status_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-29 10:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $account->addOwner($owner);
        $this->configureGlobalOllama();
        $booking = $this->bookingFor($account, $owner);
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'disposition' => 'cancel_booking',
                        'answer' => null,
                        'follow_up_actions' => [],
                        'action' => ['booking_id' => $booking->id],
                        'calendar_reference' => null,
                        'reason' => 'explicit booking cancellation',
                    ]),
                ],
            ]),
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.messages.store', $account), [
                'message' => 'Cancel booking #'.$booking->id,
            ])
            ->assertOk()
            ->assertJsonPath('pending_actions.0.action_name', 'cancel-booking');

        $booking->refresh();
        $this->assertSame(ClassBookingStatus::Booked, $booking->status);

        $actionId = $response->json('pending_actions.0.id');

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.assistant.actions.confirm', [$account, $actionId]))
            ->assertOk()
            ->assertJsonPath('pending_actions', []);

        $booking->refresh();
        $this->assertSame(ClassBookingStatus::Cancelled, $booking->status);
        $this->assertDatabaseHas('ai_pending_actions', [
            'id' => $actionId,
            'status' => AiPendingAction::StatusExecuted,
        ]);

        Carbon::setTestNow();
    }

    private function configureGlobalOllama(): void
    {
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
        ]);
    }

    private function bookingFor(Account $account, User $owner): ClassBooking
    {
        $location = Location::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'cancellation_cutoff_minutes' => null,
        ]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($classType)->create([
            'starts_at' => Carbon::parse('2026-06-30 12:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-30 13:00:00', 'UTC'),
            'room_id' => null,
            'trainer_id' => null,
        ]);
        $customer = Customer::factory()->for($account)->create();

        return ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create([
                'booked_by_user_id' => $owner->id,
                'status' => ClassBookingStatus::Booked->value,
            ]);
    }
}
