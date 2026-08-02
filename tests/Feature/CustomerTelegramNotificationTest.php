<?php

namespace Tests\Feature;

use App\Enums\CustomerNotificationChannel;
use App\Enums\CustomerNotificationStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\SmsSendingMode;
use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
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
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Support\CustomerNotifications\CustomerNotificationProducer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerTelegramNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_due_reminder_uses_the_studio_bot_for_an_actively_linked_customer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 10:00:00', config('app.timezone')));
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 44001],
        ])]);
        $fixture = $this->notificationFixture(withSms: false);

        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($fixture['booking']);

        $this->assertNotNull($notification);
        $this->assertSame(CustomerNotificationChannel::Automatic, $notification->channel);
        $notification->forceFill(['scheduled_send_at' => now()])->save();

        $this->artisan('customer-notifications:send --limit=10')->assertSuccessful();

        $notification->refresh();
        $this->assertSame(CustomerNotificationStatus::Sent, $notification->status);
        $this->assertSame(CustomerNotificationChannel::Telegram, $notification->resolved_channel);
        $this->assertSame($fixture['authorization']->id, $notification->telegram_chat_authorization_id);
        $this->assertSame('telegram', $notification->provider);
        $this->assertSame('44001', $notification->provider_message_id);
        $this->assertNull($notification->fallback_used_at);
        $this->assertDatabaseHas('telegram_messages', [
            'account_id' => $fixture['account']->id,
            'telegram_bot_installation_id' => $fixture['installation']->id,
            'telegram_chat_authorization_id' => $fixture['authorization']->id,
            'telegram_chat_id' => '771001',
            'direction' => 'outbound',
            'message_type' => 'notification',
        ]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '771001'
            && $request['text'] === $notification->text);
    }

    public function test_blocked_customer_bot_revokes_the_link_and_falls_back_to_sms(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 10:00:00', config('app.timezone')));
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.telegram.org/')) {
                return Http::response([
                    'ok' => false,
                    'description' => 'Forbidden: bot was blocked by the user',
                ], 403);
            }

            if (str_contains($request->url(), 'api.turbosms.ua/')) {
                return Http::response(['response_result' => [['message_id' => 'sms-fallback-1']]]);
            }

            return Http::response([], 404);
        });
        $fixture = $this->notificationFixture(withSms: true);
        $notification = app(CustomerNotificationProducer::class)->queueClassReminder($fixture['booking']);
        $notification->forceFill(['scheduled_send_at' => now()])->save();

        $this->artisan('customer-notifications:send --limit=10')->assertSuccessful();

        $notification->refresh();
        $this->assertSame(CustomerNotificationStatus::Sent, $notification->status);
        $this->assertSame(CustomerNotificationChannel::Sms, $notification->resolved_channel);
        $this->assertNull($notification->telegram_chat_authorization_id);
        $this->assertNotNull($notification->fallback_used_at);
        $this->assertSame(IntegrationProvider::Turbosms->value, $notification->provider);
        $this->assertSame('sms-fallback-1', $notification->provider_message_id);
        $this->assertSame(TelegramChatAuthorizationStatus::Revoked, $fixture['authorization']->refresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.turbosms.ua/')
            && $request['recipients'] === ['+380501112233']);
    }

    /**
     * @return array{account: Account, booking: ClassBooking, installation: TelegramBotInstallation, authorization: TelegramChatAuthorization}
     */
    private function notificationFixture(bool $withSms): array
    {
        $account = Account::factory()->create([
            'name' => 'Telegram Reminder Studio',
            'country_code' => 'UA',
            'default_language' => 'uk',
            'timezone' => 'Europe/Kyiv',
            'enable_customer_notifications' => true,
        ]);
        CustomerAuthSetting::create([
            'account_id' => $account->id,
            'sms_sending_mode' => $withSms ? SmsSendingMode::OwnGateway->value : SmsSendingMode::Disabled->value,
            'sms_provider' => $withSms ? IntegrationProvider::Turbosms->value : null,
        ]);
        CustomerNotificationSetting::create([
            'account_id' => $account->id,
            'is_enabled' => true,
            'class_reminder_enabled' => true,
            'class_reminder_hours_before' => 5,
        ]);

        if ($withSms) {
            IntegrationSetting::create([
                'scope_type' => IntegrationScope::Account->value,
                'scope_id' => $account->id,
                'account_id' => $account->id,
                'provider' => IntegrationProvider::Turbosms->value,
                'category' => IntegrationCategory::Messaging->value,
                'is_enabled' => true,
                'credentials' => [
                    'api_token' => 'turbo-token',
                    'sms_sender' => 'Ladna',
                ],
            ]);
        }

        $installation = TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'account',
            'scope_id' => $account->id,
            'profile' => TelegramBotProfile::Customer->value,
            'bot_id' => (string) fake()->unique()->numberBetween(700000, 799999),
            'is_enabled' => true,
        ]);
        $account->telegramBotProfiles()->create([
            'profile' => TelegramBotProfile::Customer->value,
            'mode' => TelegramBotMode::Simple->value,
            'is_enabled' => true,
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'Europe/Kyiv']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['name' => 'Reminder Class']);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Reminder Class',
                'trainer_id' => null,
                'starts_at' => now()->addHours(5),
                'ends_at' => now()->addHours(6),
            ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Telegram Customer',
            'phone' => '+380501112233',
            'default_language' => 'uk',
        ]);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($customer)
            ->create();
        $authorization = TelegramChatAuthorization::factory()
            ->for($account)
            ->for($installation, 'installation')
            ->for($customer)
            ->create([
                'user_id' => null,
                'customer_id' => $customer->id,
                'profile' => TelegramBotProfile::Customer->value,
                'telegram_chat_id' => '771001',
                'telegram_user_id' => '881001',
                'status' => TelegramChatAuthorizationStatus::Authorized->value,
            ]);

        return compact('account', 'booking', 'installation', 'authorization');
    }
}
