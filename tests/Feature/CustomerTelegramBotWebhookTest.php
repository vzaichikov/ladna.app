<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Enums\TelegramCustomerSessionState;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramCustomerSession;
use App\Models\TelegramMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
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
