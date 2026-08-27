<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Jobs\SendFestivalNotification;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalNotification;
use App\Models\FestivalNotificationPreference;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Support\Festivals\FestivalTelegramIdentityLinker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FestivalTelegramNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_required_lifecycle_notification_is_deduplicated_and_delivered_beside_email(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 44001],
        ])]);
        $fixture = $this->authorizedFestival('7101001');
        $payload = ['entry_code' => 'ENTRY-TELEGRAM-1'];

        app(FestivalNotificationOutbox::class)->queue(
            $fixture['registrant'],
            $fixture['edition'],
            FestivalNotificationType::EntrySubmitted,
            $payload,
        );
        app(FestivalNotificationOutbox::class)->queue(
            $fixture['registrant'],
            $fixture['edition'],
            FestivalNotificationType::EntrySubmitted,
            $payload,
        );

        $notifications = FestivalNotification::query()->whereBelongsTo($fixture['account'])->get();
        $this->assertCount(2, $notifications);
        $this->assertEqualsCanonicalizing(
            [FestivalNotificationChannel::Email, FestivalNotificationChannel::Telegram],
            $notifications->pluck('channel')->all(),
        );
        $telegram = $notifications->firstWhere('channel', FestivalNotificationChannel::Telegram);
        $this->assertNotNull($telegram);
        $this->assertSame($fixture['authorization']->id, $telegram->telegram_chat_authorization_id);

        app()->call([new SendFestivalNotification($telegram->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Sent, $telegram->refresh()->status);
        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $fixture['account']->id,
            'telegram_chat_authorization_id' => $fixture['authorization']->id,
            'telegram_chat_id' => '7101001',
            'direction' => 'outbound',
        ]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '7101001'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.web_app.url') === route(
                'public.festival-telegram.show',
                [$fixture['account']->slug, $fixture['series']->slug],
            ).'?action=dashboard');
    }

    public function test_phone_only_registrant_receives_required_telegram_notification_before_adding_email(): void
    {
        $fixture = $this->authorizedFestival('7101501', withEmail: false);

        $notification = app(FestivalNotificationOutbox::class)->queue(
            $fixture['registrant'],
            $fixture['edition'],
            FestivalNotificationType::EntryReviewed,
            ['entry_code' => 'PHONE-ONLY', 'status' => 'accepted'],
        );

        $this->assertNotNull($notification);
        $this->assertSame(FestivalNotificationChannel::Telegram, $notification->channel);
        $this->assertNull($notification->recipient_email);
        $this->assertSame(1, FestivalNotification::query()->whereBelongsTo($fixture['account'])->count());
    }

    public function test_optional_notification_requires_an_enabled_preference_at_queue_and_delivery_time(): void
    {
        $fixture = $this->authorizedFestival('7102001');

        app(FestivalNotificationOutbox::class)->queue(
            $fixture['registrant'],
            $fixture['edition'],
            FestivalNotificationType::Announcement,
            ['subject' => 'Optional', 'body' => 'No Telegram yet.'],
            dedupeSuffix: 'disabled',
        );
        $this->assertSame(0, FestivalNotification::query()->where('channel', FestivalNotificationChannel::Telegram->value)->count());

        $preference = FestivalNotificationPreference::query()->create([
            'account_id' => $fixture['account']->id,
            'festival_portal_user_id' => $fixture['registrant']->id,
            'type' => FestivalNotificationType::Announcement,
            'is_enabled' => true,
        ]);
        app(FestivalNotificationOutbox::class)->queue(
            $fixture['registrant'],
            $fixture['edition'],
            FestivalNotificationType::Announcement,
            ['subject' => 'Optional', 'body' => 'Telegram is allowed.'],
            dedupeSuffix: 'enabled',
        );
        $telegram = FestivalNotification::query()
            ->where('channel', FestivalNotificationChannel::Telegram->value)
            ->sole();

        $preference->update(['is_enabled' => false]);
        app()->call([new SendFestivalNotification($telegram->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Cancelled, $telegram->refresh()->status);
        $this->assertSame('festival_telegram_preference_disabled', $telegram->failure_reason);
        Http::assertNothingSent();
    }

    public function test_blocking_the_bot_revokes_series_authorization_without_affecting_email(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => false,
            'error_code' => 403,
            'description' => 'Forbidden: bot was blocked by the user',
        ], 403)]);
        $fixture = $this->authorizedFestival('7103001');
        app(FestivalNotificationOutbox::class)->queue(
            $fixture['registrant'],
            $fixture['edition'],
            FestivalNotificationType::PaymentDue,
            ['charge' => 'Participation'],
        );
        $telegram = FestivalNotification::query()
            ->where('channel', FestivalNotificationChannel::Telegram->value)
            ->sole();
        $email = FestivalNotification::query()
            ->where('channel', FestivalNotificationChannel::Email->value)
            ->sole();

        app()->call([new SendFestivalNotification($telegram->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Cancelled, $telegram->refresh()->status);
        $this->assertSame('festival_telegram_bot_blocked', $telegram->failure_reason);
        $this->assertSame(TelegramChatAuthorizationStatus::Revoked, $fixture['authorization']->refresh()->status);
        $this->assertSame(FestivalNotificationStatus::Pending, $email->refresh()->status);
    }

    public function test_disabled_series_installation_cancels_a_previously_queued_delivery(): void
    {
        $fixture = $this->authorizedFestival('7104001');
        app(FestivalNotificationOutbox::class)->queue(
            $fixture['registrant'],
            $fixture['edition'],
            FestivalNotificationType::ResultsPublished,
            ['rank' => 1],
        );
        $telegram = FestivalNotification::query()
            ->where('channel', FestivalNotificationChannel::Telegram->value)
            ->sole();
        $fixture['installation']->update(['is_enabled' => false]);

        app()->call([new SendFestivalNotification($telegram->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Cancelled, $telegram->refresh()->status);
        $this->assertSame('festival_telegram_recipient_state_changed', $telegram->failure_reason);
        Http::assertNothingSent();
    }

    public function test_ticket_notification_uses_the_linked_guest_and_never_exposes_order_bearer(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 44002],
        ])]);
        $fixture = $this->authorizedFestival('7105001');
        $guest = FestivalPortalUser::factory()->guest()->for($fixture['account'])->create([
            'telegram_user_id' => '7105001',
            'locale' => 'en',
        ]);
        app(FestivalTelegramIdentityLinker::class)->linkPortalUser($fixture['authorization'], $guest);
        $order = FestivalTicketOrder::factory()->for($fixture['edition'])->create([
            'account_id' => $fixture['account']->id,
            'festival_portal_user_id' => $guest->id,
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => null,
            'buyer_name' => $guest->displayName(),
            'buyer_email' => $guest->email,
            'locale' => 'en',
        ]);

        app(FestivalNotificationOutbox::class)->queueForTicketOrder($order, ['tickets_count' => 2]);
        $telegram = FestivalNotification::query()
            ->where('channel', FestivalNotificationChannel::Telegram->value)
            ->sole();

        $this->assertSame($guest->id, $telegram->festival_portal_user_id);
        $this->assertSame($order->id, $telegram->festival_ticket_order_id);
        $this->assertStringNotContainsString($order->access_token_encrypted, (string) $telegram->text);
        $this->assertStringNotContainsString($order->access_token_encrypted, json_encode($telegram->payload, JSON_THROW_ON_ERROR));

        app()->call([new SendFestivalNotification($telegram->id), 'handle']);

        $this->assertSame(FestivalNotificationStatus::Sent, $telegram->refresh()->status);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) data_get($request->data(), 'reply_markup.inline_keyboard.0.0.web_app.url'), 'action=ticket_order')
            && str_contains((string) data_get($request->data(), 'reply_markup.inline_keyboard.0.0.web_app.url'), 'target_id='.$order->id)
            && ! str_contains(json_encode($request->data(), JSON_THROW_ON_ERROR), $order->access_token_encrypted));
    }

    /**
     * @return array{account: Account, series: FestivalSeries, edition: FestivalEdition, installation: TelegramBotInstallation, authorization: TelegramChatAuthorization, registrant: FestivalPortalUser}
     */
    private function authorizedFestival(string $telegramUserId, bool $withEmail = true): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addHours(6),
        ]);
        $installation = TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'festival_series',
            'scope_id' => $series->id,
            'profile' => TelegramBotProfile::Festival,
            'is_enabled' => true,
        ]);
        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            $telegramUserId,
            $telegramUserId,
            '+38050'.substr($telegramUserId, -7),
            ['first_name' => 'Telegram', 'last_name' => 'Recipient', 'language_code' => 'en'],
        );
        if ($withEmail) {
            $email = 'telegram-'.$telegramUserId.'@example.test';
            $linked['registrant']->forceFill([
                'email' => $email,
                'email_normalized' => $email,
                'email_verified_at' => now(),
            ])->save();
        }

        return [
            'account' => $account,
            'series' => $series,
            'edition' => $edition,
            'installation' => $installation,
            'authorization' => $linked['authorization'],
            'registrant' => $linked['registrant'],
        ];
    }
}
