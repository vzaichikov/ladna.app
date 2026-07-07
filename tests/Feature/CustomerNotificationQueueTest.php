<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use App\Enums\CustomerOtpSenderScope;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerAuthSetting;
use App\Models\CustomerNotificationSetting;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Support\CustomerNotifications\ClassBookingNotificationCoordinator;
use App\Support\CustomerNotifications\CustomerNotificationProducer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerNotificationQueueTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_class_reminder_for_nine_am_class_is_scheduled_at_previous_day_eight_pm(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(Carbon::parse('2026-07-07 09:00:00', 'Europe/Kyiv'));

        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);

        $this->assertNotNull($notification);
        $this->assertSame(CustomerNotificationStatus::Pending, $notification->status);
        $this->assertSame(CustomerNotificationType::ClassReminder, $notification->type);
        $this->assertSame('2026-07-06 20:00:00', $notification->scheduled_send_at->timezone('Europe/Kyiv')->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('Чекаємо Вас на тренуванні у Studio SMS 07.07.2026 о 09:00', (string) $notification->text);
    }

    public function test_class_reminder_for_ten_am_class_is_scheduled_at_same_day_nine_am(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(Carbon::parse('2026-07-07 10:00:00', 'Europe/Kyiv'));

        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);

        $this->assertNotNull($notification);
        $this->assertSame('2026-07-07 09:00:00', $notification->scheduled_send_at->timezone('Europe/Kyiv')->format('Y-m-d H:i:s'));
    }

    public function test_send_command_sends_due_sms_and_marks_notification_sent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 09:30:00', config('app.timezone')));
        Http::fake([
            'api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'sms-1']]]),
        ]);
        ['booking' => $booking] = $this->notificationFixture(Carbon::parse('2026-07-07 17:30:00', 'Europe/Kyiv'));

        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);

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

        $notification->refresh();

        $this->assertSame(CustomerNotificationStatus::Sent, $notification->status);
        $this->assertSame('sms-1', $notification->provider_message_id);
        Http::assertSent(fn (Request $request): bool => $request['recipients'] === ['+380501112233']
            && $request['sms']['text'] === 'Чекаємо Вас на тренуванні у Studio SMS 07.07.2026 о 17:30');
    }

    public function test_due_notification_is_rescheduled_when_sender_runs_in_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(Carbon::parse('2026-07-07 10:00:00', 'Europe/Kyiv'));
        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);
        $notification->forceFill([
            'scheduled_send_at' => Carbon::parse('2026-07-06 20:00:00', 'Europe/Kyiv')->timezone(config('app.timezone')),
        ])->save();

        Carbon::setTestNow(Carbon::parse('2026-07-06 18:05:00', config('app.timezone')));
        Http::fake();

        $this->artisan('customer-notifications:send --limit=10')->assertSuccessful();

        $notification->refresh();

        $this->assertSame(CustomerNotificationStatus::Pending, $notification->status);
        $this->assertSame('2026-07-07 09:00:00', $notification->scheduled_send_at->timezone('Europe/Kyiv')->format('Y-m-d H:i:s'));
        Http::assertNothingSent();
    }

    public function test_booking_cancellation_cancels_pending_class_reminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(Carbon::parse('2026-07-07 17:30:00', 'Europe/Kyiv'));
        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);

        $booking->forceFill([
            'status' => ClassBookingStatus::Cancelled->value,
            'attended_at' => null,
        ])->save();
        app(ClassBookingNotificationCoordinator::class)->bookingCancelled($booking);

        $notification->refresh();

        $this->assertSame(CustomerNotificationStatus::Cancelled, $notification->status);
    }

    public function test_deleted_booking_cancels_pending_class_reminder_after_foreign_key_is_nulled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(Carbon::parse('2026-07-07 17:30:00', 'Europe/Kyiv'));
        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);

        $booking->delete();
        app(ClassBookingNotificationCoordinator::class)->bookingCancelled($booking);

        $notification->refresh();

        $this->assertNull($notification->class_booking_id);
        $this->assertSame(CustomerNotificationStatus::Cancelled, $notification->status);
    }

    /**
     * @return array{account: Account, booking: ClassBooking, scheduledClass: ScheduledClass}
     */
    private function notificationFixture(Carbon $startsAt): array
    {
        $account = Account::factory()->create([
            'name' => 'Studio SMS',
            'country_code' => 'UA',
            'default_language' => 'uk',
            'timezone' => 'Europe/Kyiv',
            'enable_customer_notifications' => true,
        ]);
        CustomerAuthSetting::create([
            'account_id' => $account->id,
            'customer_sms_sender_scope' => CustomerOtpSenderScope::Platform->value,
            'customer_sms_provider' => IntegrationProvider::Turbosms->value,
        ]);
        CustomerNotificationSetting::create([
            'account_id' => $account->id,
            'is_enabled' => true,
            'class_reminder_enabled' => true,
            'class_reminder_hours_before' => 5,
        ]);
        IntegrationSetting::create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => IntegrationProvider::Turbosms->value,
            'category' => IntegrationCategory::Messaging->value,
            'is_enabled' => true,
            'credentials' => [
                'api_token' => 'turbo-token',
                'sms_sender' => 'Ladna',
            ],
        ]);

        $location = Location::factory()->for($account)->create([
            'timezone' => 'Europe/Kyiv',
        ]);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Pole Class',
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Pole Class',
                'starts_at' => $startsAt->copy()->timezone(config('app.timezone')),
                'ends_at' => $startsAt->copy()->addHour()->timezone(config('app.timezone')),
            ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Anna',
            'phone' => '0501112233',
            'default_language' => 'uk',
        ]);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($customer)
            ->create();

        return [
            'account' => $account,
            'booking' => $booking,
            'scheduledClass' => $scheduledClass,
        ];
    }
}
