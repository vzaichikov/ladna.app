<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalEntrancePassEligibility;
use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Enums\AccountMode;
use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\SmsDeliveryStatus;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Jobs\SendFestivalNotification;
use App\Mail\FestivalPortalMail;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalAnnouncement;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalNotification;
use App\Models\FestivalNotificationPreference;
use App\Models\FestivalNotificationSetting;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTicket;
use App\Models\FestivalTicketOrder;
use App\Models\IntegrationSetting;
use App\Models\SmsDelivery;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Support\Festivals\FestivalNotificationRenderer;
use App\Support\Festivals\FestivalNotificationScenarioSettings;
use App\Support\Mail\MailDeliverySettingsResolver;
use App\Support\PhoneNumberNormalizer;
use App\Support\Sms\ResumeSmsNotificationsAfterTopUp;
use App\Support\Sms\SmsAutoTopUpService;
use App\Support\Sms\SmsRetryPolicy;
use App\Support\Sms\StudioSmsSender;
use App\Support\Sms\StudioSmsSendResult;
use App\Support\Telegram\FestivalTelegramNotificationSender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class FestivalNotificationDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    private MailManager $mailManager;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->mailManager = app(MailManager::class);
        Mail::fake();
        Http::preventStrayRequests();
    }

    public function test_unknown_sms_is_not_retried_by_a_queued_job_or_dispatcher(): void
    {
        $notification = $this->smsNotification();
        $this->mock(StudioSmsSender::class)->shouldReceive('send')->once()->andReturn(
            new StudioSmsSendResult(new SmsDelivery(['status' => SmsDeliveryStatus::Unknown]), SmsDeliveryStatus::Unknown),
        );
        app()->call([new SendFestivalNotification($notification->id), 'handle']);
        app()->call([new SendFestivalNotification($notification->id), 'handle']);
        Queue::fake();
        $this->artisan('festivals:dispatch-notifications')->assertSuccessful();

        $this->assertSame(1, $notification->refresh()->attempts);
        $this->assertSame('sms_delivery_outcome_unknown', $notification->failure_reason);
        Queue::assertNotPushed(SendFestivalNotification::class, fn ($job): bool => $job->notificationId === $notification->id);
    }

    public function test_interrupted_sms_is_held_as_unknown_instead_of_resent(): void
    {
        $notification = $this->smsNotification();
        $notification->update(['status' => FestivalNotificationStatus::Sending, 'attempts' => 1]);
        DB::table('festival_notifications')->where('id', $notification->id)->update(['updated_at' => now()->subMinutes(6)]);
        Queue::fake();
        $this->mock(StudioSmsSender::class)->shouldNotReceive('send');

        $this->artisan('festivals:dispatch-notifications')->assertSuccessful();
        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Failed, $notification->refresh()->status);
        $this->assertSame('sms_delivery_outcome_unknown', $notification->failure_reason);
        $this->assertSame(1, $notification->attempts);
        Queue::assertNotPushed(SendFestivalNotification::class, fn ($job): bool => $job->notificationId === $notification->id);
    }

    public function test_transient_sms_retries_follow_shared_backoff_and_can_recover_beyond_five_attempts(): void
    {
        $notification = $this->smsNotification();
        $notification->update(['attempts' => 5, 'failure_reason' => SmsRetryPolicy::TransientFailure]);
        $sender = $this->mock(StudioSmsSender::class);
        $sender->shouldReceive('send')->once()->andReturn(new StudioSmsSendResult(
            new SmsDelivery(['error_code' => SmsRetryPolicy::TransientFailure]), SmsDeliveryStatus::Failed,
        ));

        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        $this->assertSame(6, $notification->refresh()->attempts);
        $this->assertSame(FestivalNotificationStatus::Pending, $notification->status);
        $this->assertSame(SmsRetryPolicy::TransientFailure, $notification->failure_reason);
        $this->assertTrue($notification->available_at->equalTo(now()->addMinutes(30)->startOfSecond()));
        app()->call([new SendFestivalNotification($notification->id), 'handle']);
        Queue::fake();
        $this->artisan('festivals:dispatch-notifications')->assertSuccessful();
        Queue::assertNotPushed(SendFestivalNotification::class, fn ($job): bool => $job->notificationId === $notification->id);

        $this->travel(31)->minutes();
        $this->artisan('festivals:dispatch-notifications')->assertSuccessful();
        Queue::assertPushed(SendFestivalNotification::class, fn ($job): bool => $job->notificationId === $notification->id);
        $sender->shouldReceive('send')->once()->andReturn(new StudioSmsSendResult(new SmsDelivery, SmsDeliveryStatus::Accepted));
        app()->call([new SendFestivalNotification($notification->id), 'handle']);
        $this->assertSame(FestivalNotificationStatus::Sent, $notification->refresh()->status);
        $this->assertSame(7, $notification->attempts);
        $this->travelBack();
    }

    public function test_transient_sms_budget_is_enforced_by_job_and_dispatcher(): void
    {
        $notification = $this->smsNotification();
        $notification->update(['attempts' => SmsRetryPolicy::MaxAttempts - 1, 'failure_reason' => SmsRetryPolicy::TransientFailure]);
        $this->mock(StudioSmsSender::class)->shouldReceive('send')->once()->andReturn(new StudioSmsSendResult(
            new SmsDelivery(['error_code' => SmsRetryPolicy::TransientFailure]), SmsDeliveryStatus::Failed,
        ));

        app()->call([new SendFestivalNotification($notification->id), 'handle']);
        app()->call([new SendFestivalNotification($notification->id), 'handle']);
        Queue::fake();
        $this->artisan('festivals:dispatch-notifications')->assertSuccessful();

        $this->assertSame(SmsRetryPolicy::MaxAttempts, $notification->refresh()->attempts);
        $this->assertSame(FestivalNotificationStatus::Failed, $notification->status);
        Queue::assertNotPushed(SendFestivalNotification::class, fn ($job): bool => $job->notificationId === $notification->id);
    }

    public function test_explicit_rejection_retries_sms_without_falling_through_to_email(): void
    {
        $notification = $this->smsNotification();
        $notification->update(['status' => FestivalNotificationStatus::Failed, 'attempts' => 1, 'failure_reason' => 'sms_provider_rejected']);
        $sender = $this->mock(StudioSmsSender::class);
        $sender->shouldReceive('send')->once()->andReturn(new StudioSmsSendResult(
            new SmsDelivery(['error_code' => 'sms_provider_rejected']), SmsDeliveryStatus::Failed,
        ));

        try {
            app()->call([new SendFestivalNotification($notification->id), 'handle']);
            $this->fail('Explicit SMS rejection should retain the existing queue retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('sms_provider_rejected', $exception->getMessage());
        }

        $this->assertSame(FestivalNotificationStatus::Failed, $notification->refresh()->status);
        $this->assertSame('sms_provider_rejected', $notification->failure_reason);
        $this->assertSame(2, $notification->attempts);
        $this->assertTrue($notification->available_at->isFuture());
        Mail::assertNothingSent();

        $this->travel(3)->minutes();
        $sender->shouldReceive('send')->once()->andReturn(new StudioSmsSendResult(new SmsDelivery, SmsDeliveryStatus::Accepted));
        app()->call([new SendFestivalNotification($notification->id), 'handle']);
        $this->assertSame(FestivalNotificationStatus::Sent, $notification->refresh()->status);
        $this->assertSame(3, $notification->attempts);
        Mail::assertNothingSent();
        $this->travelBack();
    }

    public function test_exception_after_a_previous_rejection_is_still_an_unknown_new_attempt(): void
    {
        $notification = $this->smsNotification();
        $notification->update(['status' => FestivalNotificationStatus::Failed, 'attempts' => 1, 'failure_reason' => 'sms_provider_rejected']);
        $this->mock(StudioSmsSender::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('Lost response.'));

        app()->call([new SendFestivalNotification($notification->id), 'handle']);
        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Failed, $notification->refresh()->status);
        $this->assertSame('sms_delivery_outcome_unknown', $notification->failure_reason);
        $this->assertSame(2, $notification->attempts);
        Mail::assertNothingSent();
    }

    public function test_sms_credit_resume_keeps_the_extended_transient_retry_budget(): void
    {
        $notification = $this->smsNotification();
        $notification->update(['attempts' => 6, 'failure_reason' => SmsRetryPolicy::TransientFailure]);
        $sender = $this->mock(StudioSmsSender::class);
        $sender->shouldReceive('send')->once()->andReturn(new StudioSmsSendResult(new SmsDelivery, SmsDeliveryStatus::WaitingForCredit));
        $this->mock(SmsAutoTopUpService::class)->shouldReceive('attempt')->once()->andReturnNull();
        app()->call([new SendFestivalNotification($notification->id), 'handle']);
        $this->assertSame(FestivalNotificationStatus::WaitingForSmsCredit, $notification->refresh()->status);
        $this->assertSame(1, app(ResumeSmsNotificationsAfterTopUp::class)->execute($notification->account));

        $sender->shouldReceive('send')->once()->andReturn(new StudioSmsSendResult(new SmsDelivery, SmsDeliveryStatus::Accepted));
        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Sent, $notification->refresh()->status);
        $this->assertSame(8, $notification->attempts);
    }

    private function smsNotification(): FestivalNotification
    {
        [, $edition, $portalUser] = $this->festival();
        FestivalNotificationSetting::query()->create([
            'account_id' => $edition->account_id,
            'type' => FestivalNotificationType::Announcement,
            'is_enabled' => true,
            'is_optional' => true,
            'send_sms' => true,
        ]);
        app(FestivalNotificationOutbox::class)->queue($portalUser, $edition, FestivalNotificationType::Announcement, ['subject' => 'Program update', 'body' => 'The program is ready.']);

        return FestivalNotification::query()->where('account_id', $edition->account_id)->where('channel', FestivalNotificationChannel::Sms)->sole();
    }

    public function test_ticket_seat_summary_is_localized_and_sms_and_old_payloads_stay_compatible(): void
    {
        $renderer = app(FestivalNotificationRenderer::class);
        foreach (['en' => 'General admission', 'uk' => 'Вільний вхід'] as $locale => $generalLabel) {
            $payload = ['festival' => 'Festival', 'order_id' => 'ORDER', 'tickets_count' => 3, 'seat_labels' => ['Balcony · VIP · Table A · Seat 2', 'Main · Row B · Seat 4'], 'unassigned_tickets_count' => 1];
            $message = $renderer->render(FestivalNotificationType::TicketsIssued, $locale, 'Guest', $payload);
            $this->assertStringContainsString($payload['seat_labels'][0], $message->emailText());
            $this->assertStringContainsString($payload['seat_labels'][1], $message->telegramText());
            $this->assertStringContainsString($generalLabel, $message->emailText());
            $this->assertStringNotContainsString('Balcony', $message->smsText);
            $old = $renderer->render(FestivalNotificationType::TicketsIssued, $locale, 'Guest', ['tickets_count' => 3, 'festival' => 'Festival']);
            $this->assertNotEmpty($old->emailText());
            $this->assertStringNotContainsString($generalLabel, $old->emailText());
        }
    }

    public function test_email_and_sms_are_independent_per_scenario_channels(): void
    {
        [$account, $edition, $portalUser] = $this->festival(locale: 'en');
        config(['mail.default' => 'log']);
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::MailDelivery->value,
            'category' => IntegrationCategory::Email->value,
            'is_enabled' => true,
            'credentials' => [
                'engine' => 'sendpulse_api',
                'fallback_engine' => 'log',
                'mail_from_email' => 'festival@ladna.test',
                'mail_from_name' => 'Ladna Festival',
                'sendpulse_api_key' => 'festival-mail-api-key',
            ],
        ]);
        FestivalNotificationPreference::query()->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $portalUser->id,
            'type' => FestivalNotificationType::EntrySubmitted->value,
            'is_enabled' => false,
        ]);
        FestivalNotificationSetting::query()->create([
            'account_id' => $account->id,
            'type' => FestivalNotificationType::EntrySubmitted->value,
            'is_enabled' => false,
            'is_optional' => true,
            'send_email' => true,
            'send_sms' => true,
        ]);

        $payload = ['entry_code' => 'ENTRY-100', 'festival' => $edition->title];
        app(FestivalNotificationOutbox::class)->queue($portalUser, $edition, FestivalNotificationType::EntrySubmitted, $payload);
        app(FestivalNotificationOutbox::class)->queue($portalUser, $edition, FestivalNotificationType::EntrySubmitted, $payload);

        $notifications = FestivalNotification::query()->whereBelongsTo($account)->orderBy('channel')->get();
        $this->assertCount(2, $notifications);
        $this->assertEqualsCanonicalizing(
            [FestivalNotificationChannel::Email, FestivalNotificationChannel::Sms],
            $notifications->pluck('channel')->all(),
        );
        $email = $notifications->firstWhere('channel', FestivalNotificationChannel::Email);
        $sms = $notifications->firstWhere('channel', FestivalNotificationChannel::Sms);
        $this->assertNotNull($email);
        $this->assertNotNull($sms);
        $this->assertStringContainsString('ENTRY-100', (string) $email->text);
        $this->assertStringContainsString('ENTRY-100', (string) $sms->text);
        $this->assertNotSame($email->dedupe_key, $sms->dedupe_key);

        app()->call([new SendFestivalNotification($email->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Sent, $email->refresh()->status);
        Mail::assertSent(FestivalPortalMail::class, fn (FestivalPortalMail $mail): bool => $mail->subjectLine === $email->subject
            && $mail->usesMailer(MailDeliverySettingsResolver::MailerName)
            && $mail->from === [[
                'name' => 'Ladna Festival',
                'address' => 'festival@ladna.test',
            ]]);
    }

    public function test_private_editions_do_not_queue_notifications_and_cancel_pending_delivery(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $outbox = app(FestivalNotificationOutbox::class);
        $pending = $outbox->queue($portalUser, $edition, FestivalNotificationType::EntrySubmitted, ['entry_code' => 'ENTRY-PRIVATE']);
        $this->assertNotNull($pending);
        $edition->update(['is_private' => true]);

        $this->assertNull($outbox->queue($portalUser, $edition, FestivalNotificationType::EntrySubmitted, ['entry_code' => 'ENTRY-HIDDEN']));
        $this->assertNull($outbox->queueForEntrancePasses($portalUser, $edition, 1, 'private-pass'));
        $order = FestivalTicketOrder::factory()->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id]);
        $this->assertNull($outbox->queueForTicketOrder($order, ['tickets_count' => 1]));
        app()->call([new SendFestivalNotification($pending->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Cancelled, $pending->refresh()->status);
        $this->assertSame('private_festival', $pending->failure_reason);
        $this->assertSame(1, FestivalNotification::query()->where('festival_edition_id', $edition->id)->count());
        Mail::assertNothingSent();
    }

    public function test_disabled_email_scenario_is_applied_when_queueing_and_again_before_delivery(): void
    {
        [$account, $edition, $portalUser] = $this->festival(locale: 'en');
        $setting = FestivalNotificationSetting::query()->create([
            'account_id' => $account->id,
            'type' => FestivalNotificationType::EntrySubmitted,
            'is_enabled' => true,
            'is_optional' => false,
            'send_email' => true,
            'send_sms' => true,
        ]);

        app(FestivalNotificationOutbox::class)->queue(
            $portalUser,
            $edition,
            FestivalNotificationType::EntrySubmitted,
            ['entry_code' => 'EMAIL-DELIVERY-GATE'],
            dedupeSuffix: 'email-delivery-gate',
        );
        $queuedEmail = FestivalNotification::query()
            ->where('channel', FestivalNotificationChannel::Email->value)
            ->sole();

        $setting->update(['send_email' => false]);
        app()->call([new SendFestivalNotification($queuedEmail->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Cancelled, $queuedEmail->refresh()->status);
        $this->assertSame('festival_email_scenario_disabled', $queuedEmail->failure_reason);
        Mail::assertNothingSent();

        app(FestivalNotificationOutbox::class)->queue(
            $portalUser,
            $edition,
            FestivalNotificationType::EntrySubmitted,
            ['entry_code' => 'EMAIL-QUEUE-GATE'],
            dedupeSuffix: 'email-queue-gate',
        );

        $this->assertSame(1, FestivalNotification::query()
            ->whereBelongsTo($account)
            ->where('channel', FestivalNotificationChannel::Email->value)
            ->count());
        $this->assertSame(2, FestivalNotification::query()
            ->whereBelongsTo($account)
            ->where('channel', FestivalNotificationChannel::Sms->value)
            ->count());
    }

    public function test_split_field_review_scenarios_inherit_legacy_channels_until_their_own_setting_is_saved(): void
    {
        [$account, $edition, $portalUser] = $this->festival(locale: 'en');
        $legacySetting = FestivalNotificationSetting::query()->create([
            'account_id' => $account->id,
            'type' => FestivalNotificationType::RequirementReviewed,
            'is_enabled' => true,
            'is_optional' => false,
            'send_email' => true,
            'send_sms' => true,
        ]);

        app(FestivalNotificationOutbox::class)->queue(
            $portalUser,
            $edition,
            FestivalNotificationType::RequirementAccepted,
            ['entry_code' => 'FIELD-LEGACY', 'requirement' => 'Music', 'status' => 'accepted'],
            dedupeSuffix: 'field-legacy',
        );

        $legacyEmail = FestivalNotification::query()
            ->where('type', FestivalNotificationType::RequirementAccepted->value)
            ->where('channel', FestivalNotificationChannel::Email->value)
            ->sole();
        $this->assertSame(1, FestivalNotification::query()
            ->where('type', FestivalNotificationType::RequirementAccepted->value)
            ->where('channel', FestivalNotificationChannel::Sms->value)
            ->count());

        $legacySetting->update(['send_email' => false]);
        app()->call([new SendFestivalNotification($legacyEmail->id), 'handle']);
        $this->assertSame(FestivalNotificationStatus::Cancelled, $legacyEmail->refresh()->status);
        $this->assertSame('festival_email_scenario_disabled', $legacyEmail->failure_reason);

        FestivalNotificationSetting::query()->create([
            'account_id' => $account->id,
            'type' => FestivalNotificationType::RequirementAccepted,
            'is_enabled' => true,
            'is_optional' => false,
            'send_email' => true,
            'send_sms' => false,
        ]);
        app(FestivalNotificationOutbox::class)->queue(
            $portalUser,
            $edition,
            FestivalNotificationType::RequirementAccepted,
            ['entry_code' => 'FIELD-SPECIFIC', 'requirement' => 'Music', 'status' => 'accepted'],
            dedupeSuffix: 'field-specific',
        );

        $this->assertSame(2, FestivalNotification::query()
            ->where('type', FestivalNotificationType::RequirementAccepted->value)
            ->where('channel', FestivalNotificationChannel::Email->value)
            ->count());
        $this->assertSame(1, FestivalNotification::query()
            ->where('type', FestivalNotificationType::RequirementAccepted->value)
            ->where('channel', FestivalNotificationChannel::Sms->value)
            ->count());
    }

    public function test_disabled_email_scenarios_skip_ticket_order_and_entrance_pass_messages(): void
    {
        [$account, $edition, $portalUser] = $this->festival(locale: 'en');
        foreach ([FestivalNotificationType::TicketsIssued, FestivalNotificationType::EntrancePassesIssued] as $type) {
            FestivalNotificationSetting::query()->create([
                'account_id' => $account->id,
                'type' => $type,
                'is_enabled' => true,
                'is_optional' => false,
                'send_email' => false,
            ]);
        }
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => null,
            'buyer_name' => $portalUser->displayName(),
            'buyer_email' => $portalUser->email,
            'locale' => 'en',
        ]);

        $this->assertNull(app(FestivalNotificationOutbox::class)->queueForTicketOrder($order, [
            'tickets_count' => 1,
        ]));
        $this->assertNull(app(FestivalNotificationOutbox::class)->queueForEntrancePasses(
            $portalUser,
            $edition,
            1,
            'email-disabled',
        ));
        $this->assertSame(0, FestivalNotification::query()->whereBelongsTo($account)->count());
    }

    public function test_read_only_demo_neither_queues_nor_delivers_festival_notifications(): void
    {
        [$account, $edition, $portalUser] = $this->festival(locale: 'en');
        $account->forceFill(['mode' => AccountMode::DemoReadonly])->save();
        $edition->unsetRelation('account');

        $this->assertNull(app(FestivalNotificationOutbox::class)->queue(
            $portalUser,
            $edition,
            FestivalNotificationType::EntrySubmitted,
            ['entry_code' => 'DEMO-QUEUE'],
        ));
        $ticketOrder = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => 'paid',
            'paid_at' => now(),
            'buyer_email' => $portalUser->email,
        ]);
        $this->assertNull(app(FestivalNotificationOutbox::class)->queueForTicketOrder($ticketOrder, [
            'tickets_count' => 1,
        ]));
        $this->assertSame(0, FestivalNotification::query()->whereBelongsTo($account)->count());
        $this->assertSame(0, TelegramAlert::query()->whereBelongsTo($account)->count());
        Queue::assertNothingPushed();

        $account->forceFill(['mode' => AccountMode::Live])->save();
        $edition->unsetRelation('account');
        $notification = app(FestivalNotificationOutbox::class)->queue(
            $portalUser,
            $edition,
            FestivalNotificationType::EntrySubmitted,
            ['entry_code' => 'DEMO-DELIVERY'],
        );
        $this->assertNotNull($notification);

        $account->forceFill(['mode' => AccountMode::DemoReadonly])->save();
        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Cancelled, $notification->refresh()->status);
        $this->assertSame('read_only_demo', $notification->failure_reason);
        Mail::assertNothingSent();
    }

    public function test_dispatch_command_recovers_stale_sending_notifications_without_touching_active_delivery(): void
    {
        [$account, $edition, $portalUser] = $this->festival(locale: 'en');
        $attributes = [
            'account_id' => $account->id,
            'festival_portal_user_id' => $portalUser->id,
            'festival_edition_id' => $edition->id,
            'type' => FestivalNotificationType::EntrySubmitted,
            'channel' => FestivalNotificationChannel::Email,
            'status' => FestivalNotificationStatus::Sending,
            'recipient_email' => $portalUser->email,
            'recipient_name' => $portalUser->displayName(),
            'subject' => 'Festival application submitted',
            'text' => 'Application submitted.',
            'payload' => ['lines' => ['Application submitted.']],
            'attempts' => 1,
            'available_at' => now(),
        ];
        $stale = FestivalNotification::query()->create([
            ...$attributes,
            'dedupe_key' => 'stale-sending-'.fake()->uuid(),
        ]);
        DB::table((new FestivalNotification)->getTable())
            ->where('id', $stale->id)
            ->update([
                'created_at' => now()->subMinutes(6),
                'updated_at' => now()->subMinutes(6),
            ]);
        $active = FestivalNotification::query()->create([
            ...$attributes,
            'dedupe_key' => 'active-sending-'.fake()->uuid(),
        ]);
        Queue::fake();

        $this->artisan('festivals:dispatch-notifications')->assertSuccessful();

        $this->assertSame(FestivalNotificationStatus::Failed, $stale->refresh()->status);
        $this->assertSame('delivery_interrupted', $stale->failure_reason);
        $this->assertSame(FestivalNotificationStatus::Sending, $active->refresh()->status);
        Queue::assertPushed(SendFestivalNotification::class, fn (SendFestivalNotification $job): bool => $job->notificationId === $stale->id);
        Queue::assertNotPushed(SendFestivalNotification::class, fn (SendFestivalNotification $job): bool => $job->notificationId === $active->id);
    }

    public function test_delivery_claim_never_exceeds_the_job_attempt_limit(): void
    {
        [, $edition, $portalUser] = $this->festival(locale: 'en');
        $notification = app(FestivalNotificationOutbox::class)->queue(
            $portalUser,
            $edition,
            FestivalNotificationType::EntrySubmitted,
            ['entry_code' => 'ATTEMPT-LIMIT'],
        );
        $this->assertNotNull($notification);
        $notification->forceFill([
            'status' => FestivalNotificationStatus::Failed,
            'attempts' => 5,
            'failed_at' => now(),
            'failure_reason' => 'previous_delivery_failure',
        ])->save();

        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Failed, $notification->refresh()->status);
        $this->assertSame(5, $notification->attempts);
        $this->assertSame('previous_delivery_failure', $notification->failure_reason);
        Mail::assertNothingSent();
    }

    public function test_sms_waiting_for_credit_is_visible_and_can_be_resumed_without_changing_email(): void
    {
        [$account, $edition, $portalUser] = $this->festival(phone: '+380501112233');
        FestivalNotificationSetting::query()->create([
            'account_id' => $account->id,
            'type' => FestivalNotificationType::Announcement->value,
            'is_enabled' => true,
            'is_optional' => true,
            'send_sms' => true,
        ]);
        app(FestivalNotificationOutbox::class)->queue($portalUser, $edition, FestivalNotificationType::Announcement, [
            'subject' => 'Program update',
            'body' => 'The program is ready.',
        ]);
        $sms = FestivalNotification::query()->where('channel', FestivalNotificationChannel::Sms->value)->firstOrFail();

        $sender = Mockery::mock(StudioSmsSender::class);
        $sender->shouldReceive('send')->once()->andReturn(new StudioSmsSendResult(
            new SmsDelivery,
            SmsDeliveryStatus::WaitingForCredit,
            'Insufficient SMS credit.',
        ));
        $autoTopUp = Mockery::mock(SmsAutoTopUpService::class);
        $autoTopUp->shouldReceive('attempt')->once()->with(Mockery::on(fn (Account $candidate): bool => $candidate->is($account)))->andReturnNull();

        (new SendFestivalNotification($sms->id))->handle(
            $sender,
            $autoTopUp,
            app(PhoneNumberNormalizer::class),
            app(MailDeliverySettingsResolver::class),
            app(FestivalTelegramNotificationSender::class),
            app(FestivalEntrancePassEligibility::class),
            app(FestivalNotificationScenarioSettings::class),
        );

        $this->assertSame(FestivalNotificationStatus::WaitingForSmsCredit, $sms->refresh()->status);
        $this->assertSame('waiting_for_sms_credit', $sms->failure_reason);
        $email = FestivalNotification::query()->where('channel', FestivalNotificationChannel::Email->value)->firstOrFail();
        $this->assertSame(FestivalNotificationStatus::Pending, $email->status);
        $this->assertSame($edition->title.' — Program update', $email->subject);
        $this->assertStringContainsString('Фестиваль: '.$edition->title, (string) $email->text);
        $this->assertStringContainsString('The program is ready.', (string) $email->text);

        $this->assertSame(1, app(ResumeSmsNotificationsAfterTopUp::class)->execute($account));
        $this->assertSame(FestivalNotificationStatus::Pending, $sms->refresh()->status);
    }

    public function test_missing_phone_and_a_later_disabled_sms_scenario_remain_visible_as_cancelled(): void
    {
        [$account, $edition, $portalUser] = $this->festival(phone: null);
        $setting = FestivalNotificationSetting::query()->create([
            'account_id' => $account->id,
            'type' => FestivalNotificationType::Announcement->value,
            'is_enabled' => true,
            'is_optional' => true,
            'send_sms' => true,
        ]);
        app(FestivalNotificationOutbox::class)->queue($portalUser, $edition, FestivalNotificationType::Announcement, [
            'subject' => 'Announcement',
            'body' => 'Body',
        ], dedupeSuffix: 'missing-phone');
        $missingPhone = FestivalNotification::query()->where('channel', FestivalNotificationChannel::Sms->value)->firstOrFail();
        app()->call([new SendFestivalNotification($missingPhone->id), 'handle']);
        $this->assertSame(FestivalNotificationStatus::Cancelled, $missingPhone->refresh()->status);
        $this->assertSame('festival_recipient_phone_missing_or_invalid', $missingPhone->failure_reason);

        $portalUser->update(['phone' => '+380501112233', 'phone_normalized' => '+380501112233']);
        app(FestivalNotificationOutbox::class)->queue($portalUser->refresh(), $edition, FestivalNotificationType::Announcement, [
            'subject' => 'Second announcement',
            'body' => 'Body',
        ], dedupeSuffix: 'disabled-later');
        $disabledLater = FestivalNotification::query()->where('channel', FestivalNotificationChannel::Sms->value)->latest('id')->firstOrFail();
        $setting->update(['send_sms' => false]);
        app()->call([new SendFestivalNotification($disabledLater->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Cancelled, $disabledLater->refresh()->status);
        $this->assertSame('festival_sms_scenario_disabled', $disabledLater->failure_reason);
    }

    public function test_rejected_ticket_email_stays_failed_instead_of_succeeding_through_log_and_can_retry(): void
    {
        [, , , $notification] = $this->ticketEmailContext();
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::MailDelivery->value,
            'category' => IntegrationCategory::Email->value,
            'is_enabled' => true,
            'credentials' => [
                'engine' => 'sendpulse_api',
                'fallback_engine' => 'log',
                'mail_from_email' => 'mail@ladna.test',
                'mail_from_name' => 'Ladna',
                'sendpulse_api_key' => 'festival-mail-test-key',
            ],
        ]);
        Mail::swap($this->mailManager);
        Http::fake([
            'https://api.sendpulse.com/smtp/emails' => Http::sequence()
                ->push(['message' => 'Message size limit exceeded'], 422)
                ->push(['result' => true, 'id' => 'festival-ticket-message']),
        ]);

        try {
            app()->call([new SendFestivalNotification($notification->id), 'handle']);
            $this->fail('A log fallback must not mark the Festival ticket email as sent.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('Message size limit exceeded', $exception->getMessage());
        }

        $notification->refresh();
        $this->assertSame(FestivalNotificationStatus::Failed, $notification->status);
        $this->assertNull($notification->sent_at);
        $this->assertNotNull($notification->failed_at);
        $this->assertSame(1, $notification->attempts);
        $this->assertStringContainsString('Message size limit exceeded', $notification->failure_reason);
        $this->assertTrue(FestivalNotification::query()->readyForDelivery()->whereKey($notification->id)->exists());

        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Sent, $notification->refresh()->status);
        $this->assertNotNull($notification->sent_at);
        $this->assertNull($notification->failed_at);
        $this->assertSame(2, $notification->attempts);
        Http::assertSentCount(2);
    }

    public function test_ticket_email_includes_current_valid_venue_qrs_and_one_pdf_without_persisting_bearers(): void
    {
        [$account, $edition, $order, $notification, $tickets] = $this->ticketEmailContext();
        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        Mail::assertSent(FestivalPortalMail::class, 1);
        $mail = Mail::sent(FestivalPortalMail::class)->sole();
        $this->assertCount(3, $mail->tickets);
        $this->assertSame(array_map(fn (FestivalTicket $ticket): string => $ticket->code, array_slice($tickets, 0, 3)), array_column($mail->tickets, 'code'));
        foreach (array_slice($tickets, 0, 3) as $index => $ticket) {
            $this->assertSame(route('public.festival-tickets.qr', [$account->slug, $order->access_token_encrypted, $ticket->code]), $mail->tickets[$index]['qr_url']);
        }
        $this->assertSame($tickets[0]->seat->label('en'), $mail->tickets[0]['seat']);
        $this->assertSame($tickets[1]->seat->label('en'), $mail->tickets[1]['seat']);
        $this->assertSame(__('app.festival_journey.general_admission', locale: 'en'), $mail->tickets[2]['seat']);

        $this->assertCount(1, $mail->rawAttachments);
        $attachment = $mail->rawAttachments[0];
        $this->assertSame('festival-tickets-'.$order->order_id.'.pdf', $attachment['name']);
        $this->assertSame('application/pdf', $attachment['options']['mime']);
        $this->assertStringStartsWith('%PDF-', $attachment['data']);
        $this->assertSame(3, preg_match_all('/\/Type\s*\/Page\b/', $attachment['data']));
        $this->assertSame(FestivalNotificationStatus::Sent, $notification->refresh()->status);
        $stored = (string) DB::table('festival_notifications')->where('id', $notification->id)->value('payload');
        foreach ([$order->access_token_encrypted, ...array_map(fn (FestivalTicket $ticket): string => $ticket->token_encrypted, $tickets)] as $token) {
            $this->assertStringNotContainsString($token, $stored);
            $this->assertStringNotContainsString($token, (string) $notification->text);
        }
    }

    public function test_ticket_email_html_renders_each_qr_type_code_and_seat(): void
    {
        foreach (['en', 'uk'] as $locale) {
            $mail = new FestivalPortalMail(
                subjectLine: 'Festival tickets',
                greeting: 'Hello',
                lines: ['Tickets are ready.'],
                actionLabel: 'Open tickets',
                actionUrl: 'https://ladna.test/private-order',
                messageLocale: $locale,
                tickets: [
                    ['type' => 'Front & centre', 'code' => 'FST-AAAA-BBBB', 'seat' => 'Main · Row A · Seat 1', 'qr_url' => 'https://ladna.test/first-qr'],
                    ['type' => 'General admission', 'code' => 'FST-CCCC-DDDD', 'seat' => 'Standing', 'qr_url' => 'https://ladna.test/second-qr'],
                ],
            );
            $mail->assertSeeInHtml('Front & centre');
            $mail->assertSeeInHtml('FST-AAAA-BBBB');
            $mail->assertSeeInHtml('FST-CCCC-DDDD');
            $mail->assertSeeInHtml('Main · Row A · Seat 1');
            $mail->assertSeeInHtml('src="https://ladna.test/first-qr"', false);
            $mail->assertSeeInHtml('src="https://ladna.test/second-qr"', false);
            $mail->assertSeeInHtml('https://ladna.test/private-order');
            $mail->assertDontSeeInHtml('cid:');
            $mail->assertSeeInText('FST-AAAA-BBBB');
        }
    }

    #[DataProvider('unavailableTicketEmailStates')]
    public function test_unavailable_ticket_notifications_do_not_deliver_qrs_or_pdf(string $state): void
    {
        [, $edition, $order, $notification] = $this->ticketEmailContext();
        match ($state) {
            'cancelled' => $edition->update(['cancelled_at' => now()]),
            'archived' => $edition->update(['status' => FestivalEditionStatus::Archived]),
            'private' => $edition->update(['is_private' => true]),
            'refunded' => $order->update(['status' => FestivalTicketOrderStatus::Refunded]),
            'wrong_recipient' => $order->update(['buyer_email' => 'different@example.test']),
            'wrong_account' => $notification->update(['account_id' => Account::factory()->create(['enable_festivals' => true])->id]),
        };
        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        foreach (Mail::sent(FestivalPortalMail::class) as $mail) {
            $this->assertSame([], $mail->tickets);
            $this->assertSame([], $mail->rawAttachments);
        }
        if (in_array($state, ['refunded', 'wrong_recipient', 'wrong_account', 'private'], true)) {
            Mail::assertNothingSent();
            $this->assertSame(FestivalNotificationStatus::Cancelled, $notification->refresh()->status);
        } else {
            $this->assertContains($notification->refresh()->status, [FestivalNotificationStatus::Sent, FestivalNotificationStatus::Cancelled]);
        }
    }

    /** @return array<string, array{string}> */
    public static function unavailableTicketEmailStates(): array
    {
        return [
            'cancelled edition' => ['cancelled'],
            'archived edition' => ['archived'],
            'private edition' => ['private'],
            'refunded order' => ['refunded'],
            'changed recipient' => ['wrong_recipient'],
            'cross-account notification' => ['wrong_account'],
        ];
    }

    public function test_ticket_bearer_is_created_only_in_memory_when_the_email_is_sent(): void
    {
        [$account, $edition] = $this->festival();
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => null,
            'buyer_email' => 'ticket-holder@example.test',
            'locale' => 'en',
        ]);
        $accessToken = $order->access_token_encrypted;
        $expectedUrl = route('public.festival-orders.show', [$account->slug, $accessToken]);

        $notification = app(FestivalNotificationOutbox::class)->queueForTicketOrder($order, [
            'tickets_count' => 2,
            'action_url' => $expectedUrl,
        ]);
        $storedPayload = (string) DB::table('festival_notifications')->where('id', $notification->id)->value('payload');
        $this->assertStringNotContainsString($accessToken, (string) $notification->text);
        $this->assertStringNotContainsString($accessToken, $storedPayload);
        $this->assertStringNotContainsString($expectedUrl, $storedPayload);

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.communication.history', [$account, $edition]))
            ->assertOk()
            ->assertDontSee($accessToken, false);

        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        Mail::assertSent(FestivalPortalMail::class, fn (FestivalPortalMail $mail): bool => $mail->actionUrl === $expectedUrl
            && $mail->actionLabel === __('app.festival_open_tickets', locale: 'en'));
        $this->assertSame(FestivalNotificationStatus::Sent, $notification->refresh()->status);
    }

    public function test_guest_owned_ticket_notification_links_to_the_private_order_without_storing_a_bearer(): void
    {
        [$account, $edition] = $this->festival();
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create(['locale' => 'en']);
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $guest->id,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => null,
            'buyer_name' => $guest->displayName(),
            'buyer_email' => $guest->email,
            'locale' => 'en',
        ]);
        $expectedUrl = route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]);

        $notification = app(FestivalNotificationOutbox::class)->queueForTicketOrder($order, [
            'tickets_count' => 1,
        ]);

        $this->assertSame($guest->id, $notification->festival_portal_user_id);
        $storedPayload = (string) DB::table('festival_notifications')->where('id', $notification->id)->value('payload');
        $this->assertStringNotContainsString($order->access_token_encrypted, $storedPayload);
        app()->call([new SendFestivalNotification($notification->id), 'handle']);

        Mail::assertSent(FestivalPortalMail::class, fn (FestivalPortalMail $mail): bool => $mail->actionUrl === $expectedUrl
            && $mail->actionLabel === __('app.festival_open_tickets', locale: 'en'));
    }

    public function test_every_notification_type_has_immutable_ukrainian_and_english_email_and_sms_templates(): void
    {
        $renderer = app(FestivalNotificationRenderer::class);
        $payload = [
            'festival' => 'Ladna Fest',
            'entry_code' => 'ENTRY-1',
            'entry_name' => 'Solo',
            'step' => 'Review',
            'decision' => 'accepted',
            'requirement' => 'Music',
            'deadline' => '20.08.2026 18:00',
            'charge' => 'Participation',
            'rank' => 1,
            'order_id' => 'FTO-1',
            'tickets_count' => 2,
        ];

        foreach (['uk', 'en'] as $locale) {
            foreach (FestivalNotificationType::cases() as $type) {
                $message = $renderer->render($type, $locale, 'Recipient', $payload);

                $this->assertNotSame('', trim($message->subject), $locale.':'.$type->value.':subject');
                $this->assertNotSame('', trim($message->emailText()), $locale.':'.$type->value.':email');
                $this->assertNotSame('', trim($message->smsText), $locale.':'.$type->value.':sms');
                $this->assertStringContainsString('Ladna Fest', $message->subject, $locale.':'.$type->value.':subject-festival');
                $this->assertStringContainsString('Ladna Fest', $message->emailText(), $locale.':'.$type->value.':email-festival');
                $this->assertStringNotContainsString('festival_notification_template_', $message->subject);
                $this->assertStringNotContainsString('festival_notification_template_', $message->smsText);
            }
        }
    }

    public function test_festival_mail_renders_the_festival_name_in_its_subject_and_body(): void
    {
        $message = app(FestivalNotificationRenderer::class)->render(
            FestivalNotificationType::EntrySubmitted,
            'en',
            'Recipient',
            [
                'festival' => 'Ladna Fest',
                'entry_code' => 'ENTRY-1',
            ],
        );
        $mail = new FestivalPortalMail(
            subjectLine: $message->subject,
            greeting: $message->greeting,
            lines: $message->lines,
            messageLocale: 'en',
        );

        $mail->assertHasSubject('Ladna Fest — Festival application submitted');
        $mail->assertSeeInHtml('Festival: Ladna Fest');
        $mail->assertSeeInText('Festival: Ladna Fest');
    }

    public function test_review_notifications_localize_decisions_and_explain_the_next_payment_step(): void
    {
        $renderer = app(FestivalNotificationRenderer::class);
        $actionUrl = 'https://ladna.test/festival/application';

        foreach (['uk', 'en'] as $locale) {
            $approved = $renderer->render(FestivalNotificationType::EntryStepReviewed, $locale, 'Recipient', [
                'entry_code' => 'ENTRY-STEP',
                'step' => 'Questionnaire',
                'decision' => 'approve',
                'next_step' => 'Participation payment',
                'next_step_type' => 'payment',
                'action_url' => $actionUrl,
            ]);
            $changes = $renderer->render(FestivalNotificationType::EntryStepReviewed, $locale, 'Recipient', [
                'entry_code' => 'ENTRY-STEP',
                'step' => 'Questionnaire',
                'decision' => 'request_changes',
                'comment' => 'Replace the file.',
                'correction_due_at' => '20.08.2026 18:00',
                'action_url' => $actionUrl,
            ]);
            $requirement = $renderer->render(FestivalNotificationType::RequirementAccepted, $locale, 'Recipient', [
                'entry_code' => 'ENTRY-STEP',
                'requirement' => 'Music',
                'status' => 'accepted',
                'action_url' => $actionUrl,
            ]);
            $acceptedEntry = $renderer->render(FestivalNotificationType::EntryReviewed, $locale, 'Recipient', [
                'entry_code' => 'ENTRY-STEP',
                'status' => 'accepted',
                'action_url' => $actionUrl,
            ]);
            $rejectedEntry = $renderer->render(FestivalNotificationType::EntryStepReviewed, $locale, 'Recipient', [
                'entry_code' => 'ENTRY-STEP',
                'step' => 'Questionnaire',
                'decision' => 'reject_entry',
                'comment' => 'Eligibility was not confirmed.',
                'action_url' => $actionUrl,
            ]);

            $this->assertStringContainsString($locale === 'uk' ? 'Можна переходити до оплати' : 'You can proceed to payment', $approved->smsText);
            $this->assertStringContainsString($locale === 'uk' ? 'повернуто на доопрацювання' : 'returned for additional processing', $changes->emailText());
            $this->assertStringContainsString($locale === 'uk' ? 'прийнято' : 'accepted', $requirement->emailText());
            $this->assertStringContainsString($locale === 'uk' ? 'заявники стали учасниками фестивалю' : 'applicants are now Festival participants', $acceptedEntry->emailText());
            $this->assertStringContainsString($locale === 'uk' ? 'заявку ENTRY-STEP не прийнято' : 'application ENTRY-STEP was not accepted', $rejectedEntry->emailText());
            $this->assertStringNotContainsString('Decision: approve', $approved->emailText());
            $this->assertStringNotContainsString('request_changes', $changes->emailText());
            $this->assertStringNotContainsString('reject_entry', $rejectedEntry->emailText());
            $this->assertSame(__('app.festival_open_application', locale: $locale), $approved->actionLabel);
            $this->assertSame($actionUrl, $approved->actionUrl);
        }
    }

    public function test_step_completion_overrides_are_channel_specific_and_preserve_fixed_message_parts(): void
    {
        $renderer = app(FestivalNotificationRenderer::class);
        $actionUrl = 'https://ladna.test/festival/application/custom';
        $payload = [
            'festival' => 'Ladna Fest',
            'entry_code' => 'ENTRY-CUSTOM',
            'step' => 'Questionnaire',
            'decision' => 'approve',
            'action_url' => $actionUrl,
        ];
        $message = $renderer->render(
            FestivalNotificationType::EntryStepReviewed,
            'en',
            'Registrant',
            $payload,
            [
                'email' => 'Custom email body.',
                'sms' => 'Custom SMS body.',
                'telegram' => 'Custom Telegram body.',
            ],
        );

        $this->assertStringContainsString('Custom email body.', $message->emailText());
        $this->assertStringNotContainsString('Custom Telegram body.', $message->emailText());
        $this->assertSame('Custom SMS body.', $message->smsText);
        $this->assertStringContainsString('Custom Telegram body.', $message->telegramText());
        $this->assertStringNotContainsString('Custom email body.', $message->telegramText());
        $this->assertStringContainsString('Festival: Ladna Fest', $message->emailText());
        $this->assertStringContainsString($actionUrl, $message->emailText());
        $this->assertStringContainsString($actionUrl, $message->telegramText());
        $this->assertSame(__('app.festival_open_application', locale: 'en'), $message->actionLabel);

        $ukrainian = $renderer->render(
            FestivalNotificationType::EntryStepReviewed,
            'uk',
            'Учасниця',
            [...$payload, 'festival' => 'Фестиваль Ladna'],
            [
                'email' => 'Власний текст листа.',
                'sms' => 'Власний текст SMS.',
                'telegram' => 'Власний текст Telegram.',
            ],
        );
        $this->assertStringContainsString('Власний текст листа.', $ukrainian->emailText());
        $this->assertSame('Власний текст SMS.', $ukrainian->smsText);
        $this->assertStringContainsString('Власний текст Telegram.', $ukrainian->telegramText());
        $this->assertStringContainsString('Фестиваль: Фестиваль Ladna', $ukrainian->emailText());
        $this->assertSame(__('app.festival_open_application', locale: 'uk'), $ukrainian->actionLabel);

        $fallback = $renderer->render(
            FestivalNotificationType::EntryStepReviewed,
            'en',
            'Registrant',
            $payload,
            ['email' => 'Only email is overridden.'],
        );
        $this->assertStringContainsString('Only email is overridden.', $fallback->emailText());
        $this->assertStringContainsString('is approved', $fallback->smsText);
        $this->assertStringContainsString('has been approved', $fallback->telegramText());

        $changes = $renderer->render(
            FestivalNotificationType::EntryStepReviewed,
            'en',
            'Registrant',
            [...$payload, 'decision' => 'request_changes'],
            ['email' => 'Must not replace corrections.'],
        );
        $this->assertStringNotContainsString('Must not replace corrections.', $changes->emailText());
        $this->assertStringContainsString('returned for additional processing', $changes->emailText());

        $mail = new FestivalPortalMail(
            subjectLine: $message->subject,
            greeting: $message->greeting,
            lines: $message->lines,
            actionLabel: $message->actionLabel,
            actionUrl: $message->actionUrl,
            messageLocale: 'en',
        );
        $mail->assertSeeInHtml('Custom email body.');
        $mail->assertSeeInHtml($actionUrl);
        $mail->assertSeeInHtml(__('app.festival_mail_footer', locale: 'en'));
    }

    public function test_enabled_scenario_notifies_each_connected_studio_owner_through_the_general_ladna_bot(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 501],
        ])]);
        [$account, $edition] = $this->festival(locale: 'en');
        $entry = FestivalEntry::query()->where('festival_edition_id', $edition->id)->firstOrFail();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $installation = TelegramBotInstallation::factory()->platformOwner()->create([
            'encrypted_token' => '123456:festival-owner-secret',
            'is_enabled' => true,
        ]);
        $authorization = TelegramChatAuthorization::factory()->for($account)->for($owner)->create([
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_id' => 'festival-owner-chat',
            'telegram_user_id' => 'festival-owner-user',
        ]);
        $otherAccount = Account::factory()->create();
        $otherOwner = User::factory()->create();
        $otherAccount->addOwner($otherOwner);
        $otherAuthorization = TelegramChatAuthorization::factory()->for($otherAccount)->for($otherOwner)->create([
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_id' => 'other-studio-owner-chat',
            'telegram_user_id' => 'other-studio-owner-user',
        ]);
        FestivalNotificationSetting::query()->create([
            'account_id' => $account->id,
            'type' => FestivalNotificationType::EntrySubmitted,
            'is_enabled' => true,
            'is_optional' => false,
            'notify_owner_telegram' => true,
        ]);

        $payload = ['entry_code' => $entry->code];
        app(FestivalNotificationOutbox::class)->queueForEntry($entry, FestivalNotificationType::EntrySubmitted, $payload, 'submitted');
        app(FestivalNotificationOutbox::class)->queueForEntry($entry, FestivalNotificationType::EntrySubmitted, $payload, 'submitted');

        $alert = TelegramAlert::query()->firstOrFail();
        $this->assertSame(1, TelegramAlert::query()->count());
        $this->assertSame(TelegramAlertType::FestivalUpdate, $alert->type);
        $this->assertSame($authorization->id, $alert->telegram_chat_authorization_id);
        $this->assertSame(FestivalNotificationType::EntrySubmitted->value, $alert->payload['notification_type']);
        $this->assertStringContainsString($entry->entry_name, (string) $alert->text);
        $this->assertStringContainsString(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]), (string) $alert->text);
        $this->assertFalse(TelegramAlert::query()->where('telegram_chat_authorization_id', $otherAuthorization->id)->exists());

        $this->artisan('telegram-alerts:send')->assertSuccessful();

        $this->assertSame(TelegramAlertStatus::Sent, $alert->refresh()->status);
        $this->assertDatabaseHas((new TelegramMessage)->getTable(), [
            'telegram_chat_authorization_id' => $authorization->id,
            'message_type' => 'festival_update',
        ]);
    }

    public function test_owner_telegram_scenario_is_safely_skipped_without_a_connected_owner(): void
    {
        [$account, $edition] = $this->festival();
        $entry = FestivalEntry::query()->where('festival_edition_id', $edition->id)->firstOrFail();
        FestivalNotificationSetting::query()->create([
            'account_id' => $account->id,
            'type' => FestivalNotificationType::EntrySubmitted,
            'is_enabled' => true,
            'is_optional' => false,
            'notify_owner_telegram' => true,
        ]);

        app(FestivalNotificationOutbox::class)->queueForEntry($entry, FestivalNotificationType::EntrySubmitted, [], 'no-owner');

        $this->assertSame(0, TelegramAlert::query()->count());
    }

    public function test_announcement_modal_reopens_after_validation_and_scheduled_time_uses_edition_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-20 06:00:00', 'UTC'));

        [$account, $edition, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $url = route('dashboard.accounts.festivals.communication.announcements', [$account, $edition]);

        $this->actingAs($owner)
            ->from($url)
            ->post(route('dashboard.accounts.festivals.announcements.store', [$account, $edition]), [
                'subject' => '',
                'body' => 'Body',
            ])
            ->assertRedirect($url)
            ->assertSessionHasErrors('subject');

        $this->actingAs($owner)
            ->get($url)
            ->assertOk()
            ->assertSee('data-open="true"', false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.announcements.store', [$account, $edition]), [
                'subject' => 'Scheduled update',
                'body' => 'The final program is ready.',
                'scheduled_at' => '2026-08-20T10:00',
            ])
            ->assertRedirect($url);

        $announcement = FestivalAnnouncement::query()
            ->where('festival_edition_id', $edition->id)
            ->where('subject', 'Scheduled update')
            ->firstOrFail();
        $this->assertSame('scheduled', $announcement->status);
        $this->assertSame('2026-08-20 07:00:00', $announcement->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertSame(0, FestivalNotification::query()->where('festival_edition_id', $edition->id)->count());
        $this->assertTrue($portalUser->is_active);
    }

    /** @return array{Account, FestivalEdition, FestivalTicketOrder, FestivalNotification, list<FestivalTicket>} */
    private function ticketEmailContext(): array
    {
        [$account, $edition] = $this->festival(locale: 'en');
        $edition->update(['seating_floors' => [['id' => 'main', 'label' => 'Main hall', 'width' => 900, 'height' => 600]]]);
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => FestivalTicketOrderStatus::Paid,
            'paid_at' => now(),
            'expires_at' => null,
            'buyer_email' => 'ticket-holder@example.test',
            'locale' => 'en',
        ]);
        $venue = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'General admission']);
        $assigned = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Reserved seat', 'is_assigned_seating' => true]);
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $online = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => FestivalAdmissionDeliveryMode::OnlineStream,
            'festival_online_stream_id' => $stream->id,
        ]);
        $tickets = [];
        foreach ([[$assigned, FestivalTicketStatus::Valid], [$assigned, FestivalTicketStatus::Valid], [$venue, FestivalTicketStatus::Valid], [$venue, FestivalTicketStatus::Voided], [$venue, FestivalTicketStatus::Refunded], [$online, FestivalTicketStatus::Valid]] as $index => [$type, $status]) {
            $item = $order->items()->create([
                'account_id' => $account->id,
                'festival_admission_type_id' => $type->id,
                'admission_name' => $type->name,
                'unit_price_cents' => $type->price_cents,
                'quantity' => 1,
                'total_cents' => $type->price_cents,
            ]);
            $seat = $type->is_assigned_seating ? $edition->seats()->create([
                'account_id' => $account->id,
                'festival_admission_type_id' => $type->id,
                'floor_key' => 'main',
                'section' => 'Front',
                'group_kind' => 'row',
                'group_label' => 'A',
                'seat_number' => (string) ($index + 1),
                'x' => 1000 + $index * 1000,
                'y' => 3000,
            ]) : null;
            if ($seat) {
                $item->orderSeats()->create(['festival_seat_id' => $seat->id]);
            }
            $token = Str::random(64);
            $tickets[] = FestivalTicket::query()->create([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'festival_ticket_order_id' => $order->id,
                'festival_ticket_order_item_id' => $item->id,
                'festival_admission_type_id' => $type->id,
                'festival_seat_id' => $seat?->id,
                'code' => 'FST-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)),
                'token_encrypted' => $token,
                'token_hash' => hash('sha256', $token),
                'status' => $status,
            ]);
        }
        $notification = app(FestivalNotificationOutbox::class)->queueForTicketOrder($order, ['tickets_count' => 6]);

        return [$account, $edition, $order, $notification, $tickets];
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser} */
    private function festival(string $locale = 'uk', ?string $phone = '+380501112233'): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => $locale]);
        $edition = FestivalEdition::factory()
            ->published()
            ->for(FestivalSeries::factory()->for($account))
            ->create(['account_id' => $account->id, 'timezone' => 'Europe/Kyiv']);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'locale' => $locale,
            'phone' => $phone,
            'phone_normalized' => $phone,
        ]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);

        return [$account, $edition, $portalUser];
    }
}
