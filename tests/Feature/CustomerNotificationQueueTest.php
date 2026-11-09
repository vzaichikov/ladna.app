<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
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
use App\Support\CustomerNotifications\CustomerNotificationSender;
use App\Support\Sms\SmsRetryPolicy;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerNotificationQueueTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

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
                'waiting' => 0,
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

        $delivery = $notification->account->smsDeliveries()->sole();

        $this->assertSame(SmsDeliveryPurpose::CustomerNotification, $delivery->purpose);
        $this->assertSame(SmsDeliveryStatus::Accepted, $delivery->status);
        $this->assertSame($notification->getMorphClass(), $delivery->source_type);
        $this->assertSame($notification->id, $delivery->source_id);
        $this->assertSame($notification->text, $delivery->message_preview);
        $this->assertSame('sms-1', $delivery->provider_message_id);
        $this->assertNull($delivery->amount_cents);
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
    #[DataProvider('smsProviders')]
    public function test_safe_sms_failures_recover_after_a_four_hour_outage_without_duplicate_acceptance(IntegrationProvider $provider): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07 08:00:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(now()->setTime(20, 0), $provider);
        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);
        $notification->update(['scheduled_send_at' => now()]);
        $outageEndsAt = now()->addHours(4);
        $accepted = 0;
        $calls = 0;
        Http::fake(function ($request) use ($outageEndsAt, $provider, &$accepted, &$calls) {
            $calls++;
            if (now()->lt($outageEndsAt)) {
                throw new ConnectionException('Connection refused.', previous: new ConnectException(
                    'Connection refused.', new GuzzleRequest('POST', $request->url()),
                    handlerContext: ['errno' => 7, 'request_size' => 0, 'size_upload' => 0, 'http_code' => 0, 'redirect_count' => 0],
                ));
            }
            $accepted++;

            return Http::response(match ($provider) {
                IntegrationProvider::Smsclub => ['success_request' => ['info' => ['recovered-id' => '+380501112233']]],
                IntegrationProvider::Turbosms => ['response_result' => [['message_id' => 'recovered-id']]],
                IntegrationProvider::Sendpulse => ['data' => ['id' => 'recovered-id']],
            });
        });
        $sender = app(CustomerNotificationSender::class);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->assertSame(1, $sender->sendPending()['processed']);
            $notification->refresh();
            if ($notification->status === CustomerNotificationStatus::Pending) {
                $this->assertNotNull($notification->next_attempt_at);
                $this->assertSame(0, $sender->sendPending()['processed']);
                Carbon::setTestNow($notification->next_attempt_at);
            } else {
                break;
            }
        }

        $this->assertSame(CustomerNotificationStatus::Sent, $notification->status);
        $this->assertGreaterThan(3, $notification->attempts);
        $this->assertSame('recovered-id', $notification->provider_message_id);
        $this->assertSame(1, $accepted);
        $this->assertSame(1, $booking->account->smsDeliveries()->where('status', SmsDeliveryStatus::Accepted)->count());
        $this->assertSame(0, $sender->sendPending()['processed']);
    }

    /** @return array<string, array{IntegrationProvider}> */
    public static function smsProviders(): array
    {
        return ['smsclub' => [IntegrationProvider::Smsclub], 'turbosms' => [IntegrationProvider::Turbosms], 'sendpulse' => [IntegrationProvider::Sendpulse]];
    }

    public function test_transient_retries_exhaust_the_shared_budget(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07 08:00:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(now()->addHours(5));
        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);
        $notification->update(['attempts' => SmsRetryPolicy::MaxAttempts - 1, 'scheduled_send_at' => now()]);
        Http::fake(['*' => Http::response([], 429)]);

        $this->assertSame(1, app(CustomerNotificationSender::class)->sendPending()['failed']);
        $this->assertSame(CustomerNotificationStatus::Failed, $notification->refresh()->status);
        $this->assertNull($notification->next_attempt_at);
        $this->assertSame(0, app(CustomerNotificationSender::class)->sendPending()['processed']);
        Http::assertSentCount(1);
    }

    public function test_ambiguous_sms_is_never_retried_by_later_sender_runs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07 08:00:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(now()->addHours(5));
        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);
        Http::fake(['*' => Http::response([], 503)]);

        app(CustomerNotificationSender::class)->sendPending();
        Carbon::setTestNow(now()->addHours(4));
        $this->assertSame(0, app(CustomerNotificationSender::class)->sendPending()['processed']);
        $this->assertSame('sms_delivery_outcome_unknown', $notification->refresh()->last_error);
        $this->assertSame(CustomerNotificationStatus::Failed, $notification->status);
        Http::assertSentCount(1);
    }

    public function test_cancelled_booking_is_not_sent_after_transient_failure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07 08:00:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(now()->addHours(5));
        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);
        Http::fake(['*' => Http::response([], 429)]);
        app(CustomerNotificationSender::class)->sendPending();
        $booking->update(['status' => ClassBookingStatus::Cancelled]);
        Carbon::setTestNow($notification->refresh()->next_attempt_at);

        $this->assertSame(1, app(CustomerNotificationSender::class)->sendPending()['cancelled']);
        Http::assertSentCount(1);
    }

    public function test_transient_retry_waits_through_quiet_hours_before_recovering(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07 18:59:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(Carbon::parse('2026-11-08 12:00:00', 'Europe/Kyiv'));
        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);
        $notification->update(['scheduled_send_at' => now()]);
        Http::fake(['*' => Http::sequence()->push([], 429)->push(['response_result' => [['message_id' => 'morning-id']]])]);
        $sender = app(CustomerNotificationSender::class);

        $this->assertSame(1, $sender->sendPending()['retried']);
        Carbon::setTestNow($notification->refresh()->next_attempt_at);
        $this->assertSame(1, $sender->sendPending()['rescheduled']);
        $this->assertSame('2026-11-08 09:00:00', $notification->refresh()->scheduled_send_at->timezone('Europe/Kyiv')->format('Y-m-d H:i:s'));
        Http::assertSentCount(1);
        Carbon::setTestNow($notification->scheduled_send_at->copy()->timezone(config('app.timezone')));

        $this->assertSame(1, $sender->sendPending()['sent']);
        Http::assertSentCount(2);
    }

    public function test_ordinary_sms_rejection_keeps_the_existing_three_attempt_limit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07 08:00:00', config('app.timezone')));
        ['booking' => $booking] = $this->notificationFixture(now()->addHours(5));
        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($booking);
        $notification->update(['attempts' => 2]);
        Http::fake(['*' => Http::response([], 400)]);

        $this->assertSame(1, app(CustomerNotificationSender::class)->sendPending()['failed']);
        $this->assertSame(3, $notification->refresh()->attempts);
        $this->assertNull($notification->next_attempt_at);
        Http::assertSentCount(1);
    }

    private function notificationFixture(Carbon $startsAt, IntegrationProvider $provider = IntegrationProvider::Turbosms): array
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
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => $provider->value,
        ]);
        CustomerNotificationSetting::create([
            'account_id' => $account->id,
            'is_enabled' => true,
            'class_reminder_enabled' => true,
            'class_reminder_hours_before' => 5,
        ]);
        IntegrationSetting::create([
            'scope_type' => IntegrationScope::Account->value,
            'scope_id' => $account->id,
            'account_id' => $account->id,
            'provider' => $provider->value,
            'category' => IntegrationCategory::Messaging->value,
            'is_enabled' => true,
            'credentials' => [
                'api_token' => 'turbo-token',
                'sms_sender' => 'Ladna',
                'bearer_token' => 'smsclub-token',
                'src_addr' => 'Ladna',
                'api_key' => 'sendpulse-token',
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
