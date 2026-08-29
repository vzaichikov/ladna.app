<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalEntrancePassEligibility;
use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Enums\AccountMode;
use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\SmsDeliveryStatus;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Jobs\SendFestivalNotification;
use App\Mail\FestivalPortalMail;
use App\Models\Account;
use App\Models\FestivalAnnouncement;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalNotification;
use App\Models\FestivalNotificationPreference;
use App\Models\FestivalNotificationSetting;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Models\IntegrationSetting;
use App\Models\SmsDelivery;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Support\Festivals\FestivalNotificationRenderer;
use App\Support\Mail\MailDeliverySettingsResolver;
use App\Support\PhoneNumberNormalizer;
use App\Support\Sms\ResumeSmsNotificationsAfterTopUp;
use App\Support\Sms\SmsAutoTopUpService;
use App\Support\Sms\StudioSmsSender;
use App\Support\Sms\StudioSmsSendResult;
use App\Support\Telegram\FestivalTelegramNotificationSender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class FestivalNotificationDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
    }

    public function test_email_is_always_queued_and_sent_while_sms_is_an_explicit_per_scenario_channel(): void
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
            ->get(route('dashboard.accounts.festivals.communication', [$account, $edition, 'tab' => 'history']))
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
            $requirement = $renderer->render(FestivalNotificationType::RequirementReviewed, $locale, 'Recipient', [
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
            $this->assertStringContainsString(__('app.festival_requirement_status_accepted', locale: $locale), $requirement->emailText());
            $this->assertStringContainsString($locale === 'uk' ? 'заявники стали учасниками фестивалю' : 'applicants are now Festival participants', $acceptedEntry->emailText());
            $this->assertStringContainsString($locale === 'uk' ? 'заявку ENTRY-STEP не прийнято' : 'application ENTRY-STEP was not accepted', $rejectedEntry->emailText());
            $this->assertStringNotContainsString('Decision: approve', $approved->emailText());
            $this->assertStringNotContainsString('request_changes', $changes->emailText());
            $this->assertStringNotContainsString('reject_entry', $rejectedEntry->emailText());
            $this->assertSame(__('app.festival_open_application', locale: $locale), $approved->actionLabel);
            $this->assertSame($actionUrl, $approved->actionUrl);
        }
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
        $url = route('dashboard.accounts.festivals.communication', [$account, $edition, 'tab' => 'announcements']);

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
