<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Enums\TelegramCustomerSessionState;
use App\Enums\TelegramUpdateStatus;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\AiConversation;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramCustomerSession;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\Trainer;
use App\Models\TrainerPrivateTimeframe;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerTelegramBotWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_existing_customer_links_by_explicit_own_contact_without_creating_an_ai_conversation(): void
    {
        $this->fakeTelegram();
        $account = Account::factory()->create(['default_language' => 'en']);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Anna Customer',
            'phone' => '+380501112233',
            'phone_verified_at' => null,
        ]);
        [$installation, $webhookKey] = $this->customerInstallation($account);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 10001,
            'message' => $this->message(70001, 80001, 1, '/start'),
        ])->assertNoContent();

        $session = TelegramCustomerSession::whereBelongsTo($installation, 'installation')->sole();
        $this->assertSame(TelegramCustomerSessionState::AwaitingContact, $session->state);

        $message = $this->message(70001, 80001, 2);
        $message['contact'] = [
            'user_id' => 80001,
            'phone_number' => '+380501112233',
        ];
        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 10002,
            'message' => $message,
        ])->assertNoContent();

        $authorization = TelegramChatAuthorization::whereBelongsTo($installation, 'installation')->sole();
        $this->assertSame($account->id, $authorization->account_id);
        $this->assertSame($customer->id, $authorization->customer_id);
        $this->assertSame(TelegramBotProfile::Customer, $authorization->profile);
        $this->assertSame(TelegramChatAuthorizationStatus::Authorized, $authorization->status);
        $this->assertNotNull($customer->refresh()->phone_verified_at);
        $this->assertSame(TelegramCustomerSessionState::Idle, $session->refresh()->state);
        $this->assertDatabaseHas('telegram_messages', [
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_authorization_id' => $authorization->id,
            'direction' => 'outbound',
            'profile' => TelegramBotProfile::Customer->value,
        ]);
        $this->assertSame(0, AiConversation::query()->count());
    }

    public function test_unknown_phone_creates_a_customer_only_after_name_confirmation_and_never_crosses_studios(): void
    {
        $this->fakeTelegram();
        $otherAccount = Account::factory()->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create([
            'name' => 'Other Studio Customer',
            'phone' => '+380671234567',
        ]);
        $account = Account::factory()->create(['default_language' => 'uk']);
        [$installation, $webhookKey] = $this->customerInstallation($account);
        $chatId = 70002;
        $telegramUserId = 80002;

        $contactMessage = $this->message($chatId, $telegramUserId, 1);
        $contactMessage['contact'] = [
            'user_id' => $telegramUserId,
            'phone_number' => '+380671234567',
        ];
        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 20001,
            'message' => $contactMessage,
        ])->assertNoContent();

        $session = TelegramCustomerSession::whereBelongsTo($installation, 'installation')->sole();
        $this->assertSame(TelegramCustomerSessionState::AwaitingFullName, $session->state);
        $this->assertFalse($account->customers()->exists());

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 20002,
            'message' => $this->message($chatId, $telegramUserId, 2, 'Олена Коваль'),
        ])->assertNoContent();

        $session->refresh();
        $this->assertSame(TelegramCustomerSessionState::ConfirmingCustomer, $session->state);
        $this->assertFalse($account->customers()->exists());
        $confirmToken = $this->callbackToken($session, 'confirm_customer');

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 20003,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $confirmToken),
        ])->assertNoContent();

        $createdCustomer = $account->customers()->sole();
        $this->assertSame('Олена Коваль', $createdCustomer->name);
        $this->assertSame('+380671234567', $createdCustomer->phone);
        $this->assertNotNull($createdCustomer->phone_verified_at);
        $this->assertDatabaseHas('telegram_chat_authorizations', [
            'account_id' => $account->id,
            'telegram_bot_installation_id' => $installation->id,
            'customer_id' => $createdCustomer->id,
            'telegram_chat_id' => (string) $chatId,
            'telegram_user_id' => (string) $telegramUserId,
            'status' => TelegramChatAuthorizationStatus::Authorized->value,
        ]);
        $this->assertSame('Other Studio Customer', $otherCustomer->refresh()->name);
        $this->assertSame(1, $otherAccount->customers()->count());
    }

    public function test_linked_customer_can_book_and_cancel_a_group_class_through_confirmed_callbacks(): void
    {
        $this->fakeTelegram();
        $account = Account::factory()->create(['default_language' => 'en']);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Booking Customer',
            'phone' => '+380931234567',
        ]);
        [$installation, $webhookKey] = $this->customerInstallation($account);
        $chatId = 70003;
        $telegramUserId = 80003;
        TelegramChatAuthorization::factory()
            ->for($account)
            ->for($installation, 'installation')
            ->for($customer)
            ->create([
                'user_id' => null,
                'customer_id' => $customer->id,
                'profile' => TelegramBotProfile::Customer->value,
                'telegram_chat_id' => (string) $chatId,
                'telegram_user_id' => (string) $telegramUserId,
                'status' => TelegramChatAuthorizationStatus::Authorized->value,
            ]);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Telegram Pole Class',
            'schedule_kind' => 'group_class',
            'booking_cutoff_minutes' => null,
            'cancellation_cutoff_minutes' => null,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Telegram Pole Class',
                'trainer_id' => null,
                'starts_at' => now()->addDays(2),
                'ends_at' => now()->addDays(2)->addHour(),
                'capacity' => 10,
                'booking_cutoff_minutes' => null,
                'cancellation_cutoff_minutes' => null,
            ]);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 30001,
            'message' => $this->message($chatId, $telegramUserId, 1, '/book'),
        ])->assertNoContent();
        $session = TelegramCustomerSession::whereBelongsTo($installation, 'installation')->sole();

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 30002,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'book_date')),
        ])->assertNoContent();
        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 30003,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'book_class')),
        ])->assertNoContent();
        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 30004,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'confirm_booking')),
        ])->assertNoContent();

        $booking = ClassBooking::query()
            ->whereBelongsTo($customer)
            ->whereBelongsTo($scheduledClass, 'scheduledClass')
            ->sole();
        $this->assertSame(ClassBookingStatus::Booked, $booking->status);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 30005,
            'message' => $this->message($chatId, $telegramUserId, 5, '/bookings'),
        ])->assertNoContent();
        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 30006,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'booking_detail')),
        ])->assertNoContent();
        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 30007,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'confirm_cancel_booking')),
        ])->assertNoContent();
        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 30008,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'cancel_booking')),
        ])->assertNoContent();

        $this->assertSame(ClassBookingStatus::Cancelled, $booking->refresh()->status);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 30009,
            'message' => $this->message($chatId, $telegramUserId, 9, '/bookings'),
        ])->assertNoContent();
        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 30010,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'booking_history')),
        ])->assertNoContent();

        $historyUpdate = TelegramUpdate::query()
            ->whereBelongsTo($installation, 'installation')
            ->where('update_id', 30010)
            ->sole();
        $this->assertSame(TelegramUpdateStatus::Processed, $historyUpdate->status);
        $historyText = (string) TelegramMessage::query()
            ->whereBelongsTo($historyUpdate, 'telegramUpdate')
            ->where('direction', 'outbound')
            ->value('text');
        $this->assertStringContainsString('Telegram Pole Class', $historyText);
        $this->assertStringContainsString(
            __('app.telegram_customer_booking_status_cancelled', [], $session->refresh()->locale),
            $historyText,
        );
    }

    public function test_linked_customer_can_book_an_individual_lesson_for_one_person_through_opening_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00', 'UTC'));
        $this->fakeTelegram();
        $account = Account::factory()->create([
            'default_language' => 'en',
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '13:00'],
                2 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '13:00'],
                3 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '13:00'],
                4 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '13:00'],
                5 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '13:00'],
                6 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '13:00'],
                7 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '13:00'],
            ],
        ]);
        $customer = Customer::factory()->for($account)->create([
            'phone' => '+380931234568',
            'default_language' => 'en',
        ]);
        [$installation, $webhookKey] = $this->customerInstallation($account);
        $chatId = 70005;
        $telegramUserId = 80005;
        $this->authorizeCustomer($account, $installation, $customer, $chatId, $telegramUserId);
        $location = Location::factory()->for($account)->create(['name' => 'Main', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Private room']);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Private pole',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
            'cancellation_cutoff_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Nadia']);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 50001,
            'message' => $this->message($chatId, $telegramUserId, 1, '/book'),
        ])->assertNoContent();
        $session = TelegramCustomerSession::whereBelongsTo($installation, 'installation')->sole();
        $this->assertSame(TelegramCustomerSessionState::ChoosingPrivateDate, $session->state);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 50002,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'private_date')),
        ])->assertNoContent();
        $this->assertSame(TelegramCustomerSessionState::ChoosingPrivateTime, $session->refresh()->state);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 50003,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'private_time')),
        ])->assertNoContent();
        $this->assertSame(TelegramCustomerSessionState::ConfirmingPrivateBooking, $session->refresh()->state);

        $confirmationText = (string) TelegramMessage::query()
            ->whereBelongsTo($installation, 'installation')
            ->where('direction', 'outbound')
            ->latest('id')
            ->value('text');
        $this->assertStringContainsString('Participants: 1', $confirmationText);
        $this->assertStringContainsString('No suitable active pass was found', $confirmationText);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 50004,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'confirm_private_booking')),
        ])->assertNoContent();

        $scheduledClass = ScheduledClass::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($classType)
            ->sole();
        $booking = ClassBooking::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer)
            ->whereBelongsTo($scheduledClass, 'scheduledClass')
            ->sole();
        $this->assertSame(ClassBookingStatus::Booked, $booking->status);
        $this->assertSame($location->id, $scheduledClass->location_id);
        $this->assertSame($room->id, $scheduledClass->room_id);
        $this->assertSame($trainer->id, $scheduledClass->trainer_id);
        $this->assertSame(1, $scheduledClass->capacity);
        $this->assertFalse($scheduledClass->is_public);
        $this->assertFalse($scheduledClass->is_generated);
        $this->assertSame('2026-08-04 10:00', $scheduledClass->starts_at->format('Y-m-d H:i'));
        $this->assertSame(0, AiConversation::query()->count());

        Carbon::setTestNow();
    }

    public function test_booking_type_choice_and_trainer_timeframes_select_room_after_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00', 'UTC'));
        $this->fakeTelegram();
        $account = Account::factory()->create([
            'default_language' => 'en',
            'timezone' => 'UTC',
            'trainer_private_timeframes_enabled' => true,
            'opening_hours' => [
                1 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '18:00'],
                2 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
                3 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
                4 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '18:00'],
                5 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '18:00'],
                6 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '18:00'],
                7 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $customer = Customer::factory()->for($account)->create([
            'phone' => '+380931234569',
            'default_language' => 'en',
        ]);
        [$installation, $webhookKey] = $this->customerInstallation($account);
        $chatId = 70006;
        $telegramUserId = 80006;
        $this->authorizeCustomer($account, $installation, $customer, $chatId, $telegramUserId);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $firstRoom = Room::factory()->for($account)->for($location)->create(['name' => 'Room A']);
        Room::factory()->for($account)->for($location)->create(['name' => 'Room B']);
        $privateClassType = ClassType::factory()->for($account)->create([
            'name' => 'Private 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Private trainer']);
        $classPassPlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Private pass',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'sessions_count' => 4,
            'is_active' => true,
        ]);
        $classPassPlan->classTypes()->attach($privateClassType);
        $customerClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($classPassPlan)
            ->create([
                'plan_name' => 'Private pass',
                'sessions_count' => 4,
                'reserved_sessions_count' => 0,
                'used_sessions_count' => 0,
                'purchased_at' => now()->subDay(),
                'usable_until_at' => now()->addDays(30),
            ]);
        $groupClassType = ClassType::factory()->for($account)->create([
            'name' => 'Group class',
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($firstRoom)
            ->for($groupClassType)
            ->create([
                'starts_at' => '2026-08-05 12:00:00',
                'ends_at' => '2026-08-05 13:00:00',
                'capacity' => 10,
                'is_public' => true,
            ]);

        foreach (['15:00', '15:30'] as $time) {
            $startsAt = Carbon::parse('2026-08-04 '.$time.':00', 'UTC');
            TrainerPrivateTimeframe::factory()->create([
                'account_id' => $account->id,
                'trainer_id' => $trainer->id,
                'location_id' => $location->id,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(30),
            ]);
        }

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 60001,
            'message' => $this->message($chatId, $telegramUserId, 1, '/book'),
        ])->assertNoContent();
        $session = TelegramCustomerSession::whereBelongsTo($installation, 'installation')->sole();
        $this->assertSame(TelegramCustomerSessionState::ChoosingBookingType, $session->state);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 60002,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackTokenWithValue($session->refresh(), 'book_type', ScheduleKind::PrivateLesson->value)),
        ])->assertNoContent();
        $this->assertSame(TelegramCustomerSessionState::ChoosingPrivateDate, $session->refresh()->state);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 60003,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'private_date')),
        ])->assertNoContent();
        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 60004,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'private_time')),
        ])->assertNoContent();
        $this->assertSame(TelegramCustomerSessionState::ChoosingPrivateRoom, $session->refresh()->state);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 60005,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'private_slot_room')),
        ])->assertNoContent();
        $this->assertSame(TelegramCustomerSessionState::ConfirmingPrivateBooking, $session->refresh()->state);
        $confirmationText = (string) TelegramMessage::query()
            ->whereBelongsTo($installation, 'installation')
            ->where('direction', 'outbound')
            ->latest('id')
            ->value('text');
        $this->assertStringContainsString('Private pass', $confirmationText);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 60006,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackToken($session->refresh(), 'confirm_private_booking')),
        ])->assertNoContent();

        $privateClass = ScheduledClass::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($privateClassType)
            ->sole();
        $this->assertSame('2026-08-04 15:00', $privateClass->starts_at->format('Y-m-d H:i'));
        $this->assertContains($privateClass->room_id, $account->rooms()->pluck('id')->all());
        $this->assertSame($trainer->id, $privateClass->trainer_id);
        $booking = ClassBooking::query()
            ->whereBelongsTo($privateClass, 'scheduledClass')
            ->whereBelongsTo($customer)
            ->with('classPassReservation')
            ->sole();
        $this->assertSame($customerClassPass->id, $booking->classPassReservation?->customer_class_pass_id);
        $this->assertSame(1, $customerClassPass->refresh()->reserved_sessions_count);

        Carbon::setTestNow();
    }

    public function test_individual_lesson_direction_filters_services_inside_the_authorized_studio(): void
    {
        $this->fakeTelegram();
        $account = Account::factory()->create(['default_language' => 'en']);
        $otherAccount = Account::factory()->create();
        $customer = Customer::factory()->for($account)->create([
            'phone' => '+380931234570',
            'default_language' => 'en',
        ]);
        [$installation, $webhookKey] = $this->customerInstallation($account);
        $chatId = 70007;
        $telegramUserId = 80007;
        $this->authorizeCustomer($account, $installation, $customer, $chatId, $telegramUserId);
        $location = Location::factory()->for($account)->create();
        Room::factory()->for($account)->for($location)->create();
        Trainer::factory()->for($account)->create();
        $poleDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Pole']);
        $exoticDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Exotic']);
        $genericService = ClassType::factory()->for($account)->create([
            'name' => 'Generic private',
            'activity_direction_id' => null,
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
        ]);
        $poleService = ClassType::factory()->for($account)->create([
            'name' => 'Pole private',
            'activity_direction_id' => $poleDirection->id,
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
        ]);
        $exoticService = ClassType::factory()->for($account)->create([
            'name' => 'Exotic private',
            'activity_direction_id' => $exoticDirection->id,
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
        ]);
        $inactiveService = ClassType::factory()->for($account)->create([
            'name' => 'Inactive private',
            'activity_direction_id' => $poleDirection->id,
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'is_active' => false,
        ]);
        $otherService = ClassType::factory()->for($otherAccount)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
        ]);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 70001,
            'message' => $this->message($chatId, $telegramUserId, 1, '/book'),
        ])->assertNoContent();
        $session = TelegramCustomerSession::whereBelongsTo($installation, 'installation')->sole();
        $this->assertSame(TelegramCustomerSessionState::ChoosingPrivateDirection, $session->state);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 70002,
            'callback_query' => $this->callbackPayload($chatId, $telegramUserId, $this->callbackTokenWithValue($session->refresh(), 'private_direction', $poleDirection->id)),
        ])->assertNoContent();
        $this->assertSame(TelegramCustomerSessionState::ChoosingPrivateService, $session->refresh()->state);

        $serviceIds = collect((array) data_get($session->encrypted_context, 'callbacks', []))
            ->filter(fn (array $callback): bool => data_get($callback, 'action') === 'private_service')
            ->pluck('value')
            ->map(fn (mixed $value): int => (int) $value)
            ->values();
        $this->assertEqualsCanonicalizing([$genericService->id, $poleService->id], $serviceIds->all());
        $this->assertNotContains($exoticService->id, $serviceIds);
        $this->assertNotContains($inactiveService->id, $serviceIds);
        $this->assertNotContains($otherService->id, $serviceIds);
    }

    public function test_studio_menu_responds_when_support_contacts_include_non_http_links(): void
    {
        Http::fake(function (Request $request) {
            $buttonUrls = collect($request['reply_markup']['inline_keyboard'] ?? [])
                ->flatten(1)
                ->pluck('url')
                ->filter();
            $hasInvalidButtonUrl = $buttonUrls->contains(fn (string $url): bool => ! Str::startsWith(Str::lower($url), [
                'http://',
                'https://',
                'tg://',
            ]));

            if ($hasInvalidButtonUrl) {
                return Http::response(['ok' => false, 'description' => 'Bad Request: BUTTON_URL_INVALID'], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 901]]);
        });
        $account = Account::factory()->create([
            'default_language' => 'uk',
            'name' => 'Studio menu response',
            'support_phone_url' => '+380501112233',
            'support_viber_url' => 'viber://chat?number=%2B380501112233',
        ]);
        $customer = Customer::factory()->for($account)->create([
            'default_language' => 'uk',
            'phone' => '+380671112233',
        ]);
        [$installation, $webhookKey] = $this->customerInstallation($account);
        $chatId = 70004;
        $telegramUserId = 80004;
        TelegramChatAuthorization::factory()
            ->for($account)
            ->for($installation, 'installation')
            ->for($customer)
            ->create([
                'user_id' => null,
                'profile' => TelegramBotProfile::Customer->value,
                'telegram_chat_id' => (string) $chatId,
                'telegram_user_id' => (string) $telegramUserId,
                'status' => TelegramChatAuthorizationStatus::Authorized->value,
            ]);

        $this->postCustomerUpdate($installation, $webhookKey, [
            'update_id' => 40001,
            'message' => $this->message($chatId, $telegramUserId, 1, '🏠 Студія'),
        ])->assertNoContent();

        $outboundText = (string) TelegramMessage::query()
            ->whereBelongsTo($installation, 'installation')
            ->where('direction', 'outbound')
            ->value('text');
        $this->assertStringContainsString('Studio menu response', $outboundText);
        $this->assertStringContainsString('Телефон: +380501112233', $outboundText);
        $this->assertStringContainsString('Viber: viber://chat?number=%2B380501112233', $outboundText);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && collect($request['reply_markup']['inline_keyboard'] ?? [])
                ->flatten(1)
                ->pluck('url')
                ->filter()
                ->every(fn (string $url): bool => Str::startsWith(Str::lower($url), ['http://', 'https://', 'tg://'])));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && collect($request['reply_markup']['inline_keyboard'] ?? [])
                ->flatten(1)
                ->pluck('url')
                ->filter()
                ->contains(fn (string $url): bool => str_contains($url, '/customer/telegram-login/')));
    }

    /**
     * @return array{TelegramBotInstallation, string}
     */
    private function customerInstallation(Account $account): array
    {
        $webhookKey = TelegramBotInstallation::generateWebhookKey();
        $webhookSecret = Str::random(32);
        $installation = TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'account',
            'scope_id' => $account->id,
            'profile' => TelegramBotProfile::Customer->value,
            'bot_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'encrypted_webhook_key' => $webhookKey,
            'webhook_key_hash' => TelegramBotInstallation::hashWebhookSecret($webhookKey),
            'encrypted_webhook_secret' => $webhookSecret,
            'webhook_secret_token_hash' => TelegramBotInstallation::hashWebhookSecret($webhookSecret),
            'is_enabled' => true,
        ]);
        $account->telegramBotProfiles()->create([
            'profile' => TelegramBotProfile::Customer->value,
            'mode' => TelegramBotMode::Simple->value,
            'is_enabled' => true,
        ]);

        return [$installation, $webhookKey];
    }

    /**
     * @return array<string, mixed>
     */
    private function message(int $chatId, int $telegramUserId, int $messageId, string $text = ''): array
    {
        return [
            'message_id' => $messageId,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => [
                'id' => $telegramUserId,
                'username' => 'customer_'.$telegramUserId,
                'language_code' => 'en',
            ],
            'text' => $text,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackPayload(int $chatId, int $telegramUserId, string $token): array
    {
        return [
            'id' => Str::random(12),
            'from' => [
                'id' => $telegramUserId,
                'username' => 'customer_'.$telegramUserId,
                'language_code' => 'en',
            ],
            'message' => [
                'message_id' => 900,
                'chat' => ['id' => $chatId, 'type' => 'private'],
            ],
            'data' => 'lc:'.$token,
        ];
    }

    private function callbackToken(TelegramCustomerSession $session, string $action): string
    {
        foreach ((array) data_get($session->encrypted_context, 'callbacks', []) as $token => $callback) {
            if (data_get($callback, 'action') === $action) {
                return (string) $token;
            }
        }

        $this->fail("Callback [{$action}] was not found.");
    }

    private function callbackTokenWithValue(TelegramCustomerSession $session, string $action, mixed $value): string
    {
        foreach ((array) data_get($session->encrypted_context, 'callbacks', []) as $token => $callback) {
            if (data_get($callback, 'action') === $action && data_get($callback, 'value') === $value) {
                return (string) $token;
            }
        }

        $this->fail("Callback [{$action}] with the requested value was not found.");
    }

    private function authorizeCustomer(
        Account $account,
        TelegramBotInstallation $installation,
        Customer $customer,
        int $chatId,
        int $telegramUserId,
    ): TelegramChatAuthorization {
        return TelegramChatAuthorization::factory()
            ->for($account)
            ->for($installation, 'installation')
            ->for($customer)
            ->create([
                'user_id' => null,
                'customer_id' => $customer->id,
                'profile' => TelegramBotProfile::Customer->value,
                'telegram_chat_id' => (string) $chatId,
                'telegram_user_id' => (string) $telegramUserId,
                'status' => TelegramChatAuthorizationStatus::Authorized->value,
            ]);
    }

    private function postCustomerUpdate(TelegramBotInstallation $installation, string $webhookKey, array $payload): TestResponse
    {
        return $this->postJson(route('api.v1.telegram.webhooks.handle', $webhookKey), $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => $installation->webhookSecret(),
        ]);
    }

    private function fakeTelegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 900],
        ])]);
    }
}
