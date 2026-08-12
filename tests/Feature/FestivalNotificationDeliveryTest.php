<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\SmsDeliveryStatus;
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
use App\Models\SmsDelivery;
use App\Models\User;
use App\Support\Festivals\FestivalNotificationRenderer;
use App\Support\PhoneNumberNormalizer;
use App\Support\Sms\ResumeSmsNotificationsAfterTopUp;
use App\Support\Sms\SmsAutoTopUpService;
use App\Support\Sms\StudioSmsSender;
use App\Support\Sms\StudioSmsSendResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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
        Mail::assertSent(FestivalPortalMail::class, fn (FestivalPortalMail $mail): bool => $mail->subjectLine === $email->subject);
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

        (new SendFestivalNotification($sms->id))->handle($sender, $autoTopUp, app(PhoneNumberNormalizer::class));

        $this->assertSame(FestivalNotificationStatus::WaitingForSmsCredit, $sms->refresh()->status);
        $this->assertSame('waiting_for_sms_credit', $sms->failure_reason);
        $email = FestivalNotification::query()->where('channel', FestivalNotificationChannel::Email->value)->firstOrFail();
        $this->assertSame(FestivalNotificationStatus::Pending, $email->status);

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
                $this->assertStringNotContainsString('festival_notification_template_', $message->subject);
                $this->assertStringNotContainsString('festival_notification_template_', $message->smsText);
            }
        }
    }

    public function test_announcement_modal_reopens_after_validation_and_scheduled_time_uses_edition_timezone(): void
    {
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
