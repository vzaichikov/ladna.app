<?php

namespace Tests\Feature;

use App\Actions\CancelClassBooking;
use App\Actions\CancelScheduledClassForStudio;
use App\Actions\RestoreScheduledClassCancellation;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use App\Enums\CustomerOtpSenderScope;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\ScheduleKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerAuthSetting;
use App\Models\CustomerNotification;
use App\Models\CustomerNotificationSetting;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassCancellation;
use App\Models\TelegramAlert;
use App\Models\Trainer;
use App\Models\TrainerNotificationSetting;
use App\Support\CustomerNotifications\ClassBookingNotificationCoordinator;
use App\Support\Telegram\Alerts\QueueTrainerAssignmentTelegramAlert;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationCancellationScenarioTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_cancellation_scenarios_are_disabled_by_default(): void
    {
        ['account' => $account, 'scheduledClass' => $scheduledClass] = $this->fixture();

        app(CancelScheduledClassForStudio::class)->execute($account, $scheduledClass, null);

        $this->assertFalse($account->fresh()->trainerClassCancellationTelegramAlertsEnabled());
        $this->assertDatabaseMissing('telegram_alerts', [
            'type' => TelegramAlertType::TrainerClassCancellation->value,
        ]);
        $this->assertDatabaseMissing('customer_notifications', [
            'type' => CustomerNotificationType::ClassCancellation->value,
        ]);
    }

    public function test_studio_cancellation_queues_one_trainer_alert_and_sms_for_each_booked_customer(): void
    {
        ['account' => $account, 'scheduledClass' => $scheduledClass, 'bookings' => $bookings] = $this->fixture(
            trainerCancellationEnabled: true,
            customerCancellationEnabled: true,
            bookingCount: 2,
        );

        $cancellation = app(CancelScheduledClassForStudio::class)->execute($account, $scheduledClass, null);

        $this->assertCount(2, $cancellation->effects);
        $this->assertDatabaseHas('telegram_alerts', [
            'account_id' => $account->id,
            'scheduled_class_id' => $scheduledClass->id,
            'type' => TelegramAlertType::TrainerClassCancellation->value,
            'status' => TelegramAlertStatus::Pending->value,
        ]);
        $this->assertSame(1, TelegramAlert::query()
            ->where('scheduled_class_id', $scheduledClass->id)
            ->where('type', TelegramAlertType::TrainerClassCancellation->value)
            ->count());
        $this->assertSame(2, CustomerNotification::query()
            ->where('scheduled_class_id', $scheduledClass->id)
            ->where('type', CustomerNotificationType::ClassCancellation->value)
            ->where('status', CustomerNotificationStatus::Pending->value)
            ->count());

        foreach ($bookings as $booking) {
            $this->assertDatabaseHas('customer_notifications', [
                'class_booking_id' => $booking->id,
                'type' => CustomerNotificationType::ClassCancellation->value,
            ]);
        }
    }

    public function test_trainer_alert_is_queued_only_after_the_last_active_booking_is_cancelled(): void
    {
        ['scheduledClass' => $scheduledClass, 'bookings' => $bookings] = $this->fixture(
            trainerCancellationEnabled: true,
            bookingCount: 2,
        );
        $assignmentAlert = app(QueueTrainerAssignmentTelegramAlert::class)->execute($bookings[0]);

        app(CancelClassBooking::class)->execute($bookings[0]);

        $this->assertDatabaseMissing('telegram_alerts', [
            'scheduled_class_id' => $scheduledClass->id,
            'type' => TelegramAlertType::TrainerClassCancellation->value,
        ]);
        $this->assertSame($bookings[1]->id, $assignmentAlert?->fresh()->class_booking_id);
        $this->assertStringContainsString('Customer 1', (string) $assignmentAlert?->fresh()->text);

        app(CancelClassBooking::class)->execute($bookings[1]);

        $alert = TelegramAlert::query()
            ->where('scheduled_class_id', $scheduledClass->id)
            ->where('type', TelegramAlertType::TrainerClassCancellation->value)
            ->sole();

        $this->assertSame('all_bookings_cancelled', $alert->payload['reason']);
        $this->assertStringContainsString('Усі клієнти скасували записи', (string) $alert->text);
        $this->assertSame(TelegramAlertStatus::Failed, $assignmentAlert?->fresh()->status);
    }

    public function test_non_active_booking_transition_does_not_queue_empty_class_alert(): void
    {
        ['scheduledClass' => $scheduledClass, 'bookings' => $bookings] = $this->fixture(
            trainerCancellationEnabled: true,
        );
        $bookings[0]->update(['status' => ClassBookingStatus::NoShow->value]);

        app(CancelClassBooking::class)->execute($bookings[0]->fresh());

        $this->assertDatabaseMissing('telegram_alerts', [
            'scheduled_class_id' => $scheduledClass->id,
            'type' => TelegramAlertType::TrainerClassCancellation->value,
        ]);
    }

    public function test_rebook_and_cancel_within_same_second_creates_a_new_empty_class_event(): void
    {
        ['scheduledClass' => $scheduledClass, 'bookings' => $bookings] = $this->fixture(
            trainerCancellationEnabled: true,
        );

        app(CancelClassBooking::class)->execute($bookings[0]);
        $reactivatedBooking = $bookings[0]->fresh();
        $reactivatedBooking->forceFill(['status' => ClassBookingStatus::Booked->value])->save();
        app(ClassBookingNotificationCoordinator::class)->bookingUpdatedToActive($reactivatedBooking->fresh());
        app(CancelClassBooking::class)->execute($reactivatedBooking->fresh());

        $alerts = TelegramAlert::query()
            ->where('scheduled_class_id', $scheduledClass->id)
            ->where('type', TelegramAlertType::TrainerClassCancellation->value)
            ->get();

        $this->assertCount(2, $alerts);
        $this->assertCount(2, $alerts->pluck('dedupe_key')->unique());
    }

    public function test_studio_cancellation_without_bookings_and_closed_correction_do_not_notify(): void
    {
        ['account' => $emptyAccount, 'scheduledClass' => $emptyClass] = $this->fixture(
            trainerCancellationEnabled: true,
            customerCancellationEnabled: true,
            bookingCount: 0,
        );

        app(CancelScheduledClassForStudio::class)->execute($emptyAccount, $emptyClass, null);

        ['account' => $closedAccount, 'scheduledClass' => $closedClass, 'bookings' => $closedBookings] = $this->fixture(
            trainerCancellationEnabled: true,
            customerCancellationEnabled: true,
        );
        $closedClass->update([
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);
        $closedBookings[0]->update([
            'status' => ClassBookingStatus::Attended->value,
            'attended_at' => now()->subHour(),
        ]);

        app(CancelScheduledClassForStudio::class)->execute($closedAccount, $closedClass->fresh(), null, [
            'mode' => ScheduledClassCancellation::ModeClosedCorrection,
            'pass_effect' => ScheduledClassCancellation::PassEffectReturnSession,
            'reason' => 'Correct historical attendance',
        ]);

        $this->assertDatabaseMissing('telegram_alerts', [
            'type' => TelegramAlertType::TrainerClassCancellation->value,
        ]);
        $this->assertDatabaseMissing('customer_notifications', [
            'type' => CustomerNotificationType::ClassCancellation->value,
        ]);
    }

    public function test_cancellation_sms_is_sent_during_quiet_hours(): void
    {
        Http::fake([
            'api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'cancel-sms-1']]]),
        ]);
        ['account' => $account, 'scheduledClass' => $scheduledClass, 'bookings' => $bookings] = $this->fixture(
            customerCancellationEnabled: true,
        );
        Carbon::setTestNow(Carbon::parse('2026-07-27 02:00:00', 'Europe/Kyiv')->timezone(config('app.timezone')));

        app(CancelScheduledClassForStudio::class)->execute($account, $scheduledClass, null);
        $bookings[0]->delete();

        $this->artisan('customer-notifications:send --limit=10')
            ->expectsOutput(__('app.customer_notifications_send_command_result', [
                'processed' => 1,
                'sent' => 1,
                'retried' => 0,
                'failed' => 0,
                'cancelled' => 0,
                'skipped' => 0,
                'rescheduled' => 0,
            ]))
            ->assertSuccessful();

        $notification = CustomerNotification::query()
            ->where('type', CustomerNotificationType::ClassCancellation->value)
            ->sole();

        $this->assertSame(CustomerNotificationStatus::Sent, $notification->status);
        $this->assertNull($notification->class_booking_id);
        $this->assertSame('cancel-sms-1', $notification->provider_message_id);
        Http::assertSent(fn (Request $request): bool => $request['recipients'] === ['+380501112230']
            && str_contains((string) $request['sms']['text'], 'скасувала заняття'));
    }

    public function test_switches_disabled_after_queueing_stop_external_delivery(): void
    {
        Http::fake();
        ['account' => $account, 'scheduledClass' => $scheduledClass] = $this->fixture(
            trainerCancellationEnabled: true,
            customerCancellationEnabled: true,
        );

        app(CancelScheduledClassForStudio::class)->execute($account, $scheduledClass, null);

        $account->trainerNotificationSetting()->update([
            'class_cancellation_enabled' => false,
        ]);
        $account->customerNotificationSetting()->update([
            'class_cancellation_enabled' => false,
        ]);

        $this->artisan('telegram-alerts:send')->assertSuccessful();
        $this->artisan('customer-notifications:send')->assertSuccessful();

        $this->assertSame(
            TelegramAlertStatus::Failed,
            TelegramAlert::query()->where('type', TelegramAlertType::TrainerClassCancellation->value)->sole()->status,
        );
        $this->assertSame(
            CustomerNotificationStatus::Cancelled,
            CustomerNotification::query()->where('type', CustomerNotificationType::ClassCancellation->value)->sole()->status,
        );
        Http::assertNothingSent();
    }

    public function test_restoring_class_before_delivery_suppresses_cancellation_notifications(): void
    {
        ['account' => $account, 'scheduledClass' => $scheduledClass] = $this->fixture(
            trainerCancellationEnabled: true,
            customerCancellationEnabled: true,
        );
        $cancellation = app(CancelScheduledClassForStudio::class)->execute($account, $scheduledClass, null);

        app(RestoreScheduledClassCancellation::class)->execute($account, $scheduledClass->fresh(), null);

        $this->assertNotNull($cancellation->fresh()->restored_at);
        $this->assertSame(
            TelegramAlertStatus::Failed,
            TelegramAlert::query()->where('type', TelegramAlertType::TrainerClassCancellation->value)->sole()->status,
        );
        $this->assertSame(
            CustomerNotificationStatus::Cancelled,
            CustomerNotification::query()->where('type', CustomerNotificationType::ClassCancellation->value)->sole()->status,
        );
    }

    /**
     * @return array{account: Account, scheduledClass: ScheduledClass, bookings: array<int, ClassBooking>}
     */
    private function fixture(
        bool $trainerCancellationEnabled = false,
        bool $customerCancellationEnabled = false,
        int $bookingCount = 1,
    ): array {
        Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00', 'Europe/Kyiv')->timezone(config('app.timezone')));

        $account = Account::factory()->create([
            'name' => 'Studio Cancel',
            'country_code' => 'UA',
            'default_language' => 'uk',
            'timezone' => 'Europe/Kyiv',
            'enable_telegram_alerts' => true,
            'enable_customer_notifications' => true,
        ]);

        if ($trainerCancellationEnabled) {
            TrainerNotificationSetting::factory()->for($account)->create([
                'class_cancellation_enabled' => true,
            ]);
        }

        if ($customerCancellationEnabled) {
            CustomerNotificationSetting::factory()->for($account)->create([
                'is_enabled' => true,
                'class_cancellation_enabled' => true,
            ]);
            CustomerAuthSetting::create([
                'account_id' => $account->id,
                'customer_sms_sender_scope' => CustomerOtpSenderScope::Platform->value,
                'customer_sms_provider' => IntegrationProvider::Turbosms->value,
            ]);
            IntegrationSetting::query()->firstOrCreate(
                [
                    'scope_type' => IntegrationScope::Platform->value,
                    'scope_id' => 0,
                    'provider' => IntegrationProvider::Turbosms->value,
                    'category' => IntegrationCategory::Messaging->value,
                ],
                [
                    'is_enabled' => true,
                    'credentials' => [
                        'api_token' => 'turbo-token',
                        'sms_sender' => 'Ladna',
                    ],
                ],
            );
        }

        $location = Location::factory()->for($account)->create([
            'name' => 'Podil',
            'timezone' => 'Europe/Kyiv',
        ]);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Blue']);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Iryna']);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Pole Class',
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $startsAt = Carbon::parse('2026-07-27 11:00:00', 'Europe/Kyiv')->timezone(config('app.timezone'));
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'title' => 'Pole Class',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
                'cancellation_cutoff_minutes' => 0,
            ]);
        $bookings = [];

        for ($index = 0; $index < $bookingCount; $index++) {
            $customer = Customer::factory()->for($account)->create([
                'name' => 'Customer '.$index,
                'phone' => '05011122'.str_pad((string) (30 + $index), 2, '0', STR_PAD_LEFT),
                'default_language' => 'uk',
            ]);
            $bookings[] = ClassBooking::factory()
                ->for($account)
                ->for($scheduledClass, 'scheduledClass')
                ->for($customer)
                ->create();
        }

        return compact('account', 'scheduledClass', 'bookings');
    }
}
