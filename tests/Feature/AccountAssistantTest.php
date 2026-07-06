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
            'ollama.com/api/chat' => Http::sequence()
                ->push(['message' => ['role' => 'assistant', 'content' => '{"in_scope":true,"reason":"studio question"}']])
                ->push(['message' => ['role' => 'assistant', 'content' => 'Dashboard AI answer.']]),
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

    public function test_dashboard_message_endpoint_stores_ai_follow_up_actions(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push(['message' => ['role' => 'assistant', 'content' => '{"start_booking":false,"reason":"analytics question"}']])
                ->push(['message' => ['role' => 'assistant', 'content' => '{"in_scope":true,"reason":"studio analytics question"}']])
                ->push(['message' => ['role' => 'assistant', 'content' => json_encode([
                    'answer' => 'Tomorrow has 4 bookings.',
                    'follow_up_actions' => [
                        'Show trainer load for tomorrow',
                        'Show customers without pass reservations',
                        'Show available spots tomorrow',
                        'This fourth suggestion must be ignored',
                    ],
                ])]]),
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
            'ollama.com/api/chat' => Http::sequence()
                ->push(['message' => ['role' => 'assistant', 'content' => '{"in_scope":true,"reason":"Ladna help question"}']])
                ->push(['message' => ['role' => 'assistant', 'content' => '{"answer":"Відкрийте Клієнти й натисніть Додати клієнта.","follow_up_actions":[]}']]),
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
                    'content' => '{"start_booking":true,"reason":"direct booking request"}',
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
        PlatformAiSetting::query()->delete();
        PlatformAiSetting::factory()->create(['owner_ai_assistant_enabled' => true]);
        $booking = $this->bookingFor($account, $owner);

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
