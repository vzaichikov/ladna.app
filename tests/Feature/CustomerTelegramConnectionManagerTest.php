<?php

namespace Tests\Feature;

use App\Enums\CustomerNotificationChannel;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Enums\TelegramCustomerSessionState;
use App\Enums\TelegramUpdateStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramCustomerSession;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerTelegramConnectionManagerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_open_the_account_scoped_connection_manager(): void
    {
        [$owner, $account, $installation] = $this->studioBotFixture('first_studio_bot');
        [, $otherAccount, $otherInstallation] = $this->studioBotFixture('second_studio_bot');
        $customer = Customer::factory()->for($account)->create(['name' => 'Visible Telegram Customer']);
        $otherCustomer = Customer::factory()->for($otherAccount)->create(['name' => 'Hidden Telegram Customer']);
        $authorization = $this->authorization($account, $installation, $customer, '111001');
        $otherAuthorization = $this->authorization($otherAccount, $otherInstallation, $otherCustomer, '222001');

        TelegramMessage::factory()->for($account)->for($installation, 'installation')->create([
            'telegram_chat_authorization_id' => $authorization->id,
            'profile' => TelegramBotProfile::Customer->value,
            'telegram_chat_id' => '111001',
            'text' => 'Visible studio message',
        ]);
        TelegramMessage::factory()->for($otherAccount)->for($otherInstallation, 'installation')->create([
            'telegram_chat_authorization_id' => $otherAuthorization->id,
            'profile' => TelegramBotProfile::Customer->value,
            'telegram_chat_id' => '222001',
            'text' => 'Hidden studio message',
        ]);
        TelegramUpdate::factory()->for($account)->for($installation, 'installation')->create([
            'profile' => TelegramBotProfile::Customer->value,
            'update_id' => 771001,
            'status' => TelegramUpdateStatus::Processed->value,
            'payload' => ['message' => ['text' => 'Visible update']],
        ]);
        TelegramUpdate::factory()->for($otherAccount)->for($otherInstallation, 'installation')->create([
            'profile' => TelegramBotProfile::Customer->value,
            'update_id' => 772002,
            'status' => TelegramUpdateStatus::Processed->value,
            'payload' => ['message' => ['text' => 'Hidden update']],
        ]);
        CustomerNotification::factory()->for($account)->for($customer)->create([
            'channel' => CustomerNotificationChannel::Automatic->value,
            'resolved_channel' => CustomerNotificationChannel::Telegram->value,
            'telegram_chat_authorization_id' => $authorization->id,
            'text' => 'Visible Telegram notification',
        ]);
        CustomerNotification::factory()->for($otherAccount)->for($otherCustomer)->create([
            'channel' => CustomerNotificationChannel::Automatic->value,
            'resolved_channel' => CustomerNotificationChannel::Telegram->value,
            'telegram_chat_authorization_id' => $otherAuthorization->id,
            'text' => 'Hidden Telegram notification',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.telegram-connections.index', $account))
            ->assertOk()
            ->assertSee(__('app.telegram_customer_connection_manager'))
            ->assertSee('Visible Telegram Customer')
            ->assertDontSee('Hidden Telegram Customer');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.telegram-connections.index', [$account, 'tab' => 'messages']))
            ->assertOk()
            ->assertSee('Visible studio message')
            ->assertDontSee('Hidden studio message');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.telegram-connections.index', [$account, 'tab' => 'updates']))
            ->assertOk()
            ->assertSee('771001')
            ->assertDontSee('772002');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.telegram-connections.index', [$account, 'tab' => 'notifications']))
            ->assertOk()
            ->assertSee('Visible Telegram notification')
            ->assertDontSee('Hidden Telegram notification');

        $settingsUrl = route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'telegram']);
        $managerUrl = route('dashboard.accounts.telegram-connections.index', $account);
        $this->actingAs($owner)
            ->get($settingsUrl)
            ->assertOk()
            ->assertSee($managerUrl, false)
            ->assertSee(route('dashboard.accounts.customer-telegram-bot.webhook-status', $account), false);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee($managerUrl, false)
            ->assertSee(__('app.customer_telegram_bot_menu'));
    }

    public function test_sidebar_hides_customer_bot_manager_until_the_bot_is_configured(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertDontSee(route('dashboard.accounts.telegram-connections.index', $account), false)
            ->assertDontSee(__('app.customer_telegram_bot_menu'));
    }

    public function test_connection_manager_is_restricted_to_users_who_manage_the_account(): void
    {
        [, $account, $installation] = $this->studioBotFixture('restricted_bot');
        $outsider = User::factory()->create();
        $customer = Customer::factory()->for($account)->create();
        $authorization = $this->authorization($account, $installation, $customer, '333001');

        $this->actingAs($outsider)
            ->get(route('dashboard.accounts.telegram-connections.index', $account))
            ->assertForbidden();
        $this->actingAs($outsider)
            ->delete(route('dashboard.accounts.telegram-connections.revoke', [$account, $authorization]))
            ->assertForbidden();

        $this->assertSame(TelegramChatAuthorizationStatus::Authorized, $authorization->refresh()->status);
    }

    public function test_owner_can_restart_and_revoke_only_customer_connections_from_the_same_studio(): void
    {
        [$owner, $account, $installation] = $this->studioBotFixture('session_bot');
        [, $otherAccount, $otherInstallation] = $this->studioBotFixture('foreign_session_bot');
        $customer = Customer::factory()->for($account)->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create();
        $authorization = $this->authorization($account, $installation, $customer, '444001');
        $otherAuthorization = $this->authorization($otherAccount, $otherInstallation, $otherCustomer, '555001');
        $session = TelegramCustomerSession::query()->create([
            'account_id' => $account->id,
            'telegram_bot_installation_id' => $installation->id,
            'telegram_chat_authorization_id' => $authorization->id,
            'telegram_chat_id' => '444001',
            'telegram_user_id' => '444001',
            'locale' => 'uk',
            'state' => TelegramCustomerSessionState::ConfirmingBooking->value,
            'encrypted_context' => ['scheduled_class_id' => 123],
            'expires_at' => now()->addMinutes(15),
            'last_interaction_at' => now(),
        ]);
        $notification = CustomerNotification::factory()->for($account)->for($customer)->create([
            'telegram_chat_authorization_id' => $authorization->id,
            'resolved_channel' => CustomerNotificationChannel::Telegram->value,
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.telegram-connections.reset', [$account, $authorization]))
            ->assertRedirect()
            ->assertSessionHas('status', __('app.telegram_customer_session_reset'));

        $this->assertSame(TelegramCustomerSessionState::Idle, $session->refresh()->state);
        $this->assertNull($session->encrypted_context);
        $this->assertSame(TelegramChatAuthorizationStatus::Authorized, $authorization->refresh()->status);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.telegram-connections.revoke', [$account, $otherAuthorization]))
            ->assertNotFound();
        $this->assertSame(TelegramChatAuthorizationStatus::Authorized, $otherAuthorization->refresh()->status);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.telegram-connections.revoke', [$account, $authorization]))
            ->assertRedirect()
            ->assertSessionHas('status', __('app.telegram_support_authorization_revoked'));

        $this->assertSame(TelegramChatAuthorizationStatus::Revoked, $authorization->refresh()->status);
        $this->assertNotNull($authorization->revoked_at);
        $this->assertNull($session->refresh()->telegram_chat_authorization_id);
        $this->assertSame(TelegramCustomerSessionState::AwaitingContact, $session->state);
        $this->assertNull($notification->refresh()->telegram_chat_authorization_id);
        $this->assertNull($notification->resolved_channel);
    }

    public function test_owner_has_live_webhook_status_register_and_delete_controls_for_the_studio_bot(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/getWebhookInfo')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'url' => 'https://ladna.local/api/v1/telegram/webhooks/studio-key',
                        'pending_update_count' => 3,
                        'allowed_updates' => ['message', 'callback_query'],
                    ],
                ]);
            }

            return Http::response(['ok' => true, 'result' => true]);
        });

        [$owner, $account, $installation] = $this->studioBotFixture('webhook_controls_bot');
        $installation->forceFill([
            'webhook_url' => 'https://ladna.local/api/v1/telegram/webhooks/studio-key',
            'is_enabled' => false,
        ])->save();

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.customer-telegram-bot.webhook-status', $account))
            ->assertOk()
            ->assertJsonPath('local.bot_username', 'webhook_controls_bot')
            ->assertJsonPath('telegram.url_matches', true)
            ->assertJsonPath('telegram.pending_update_count', 3);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.customer-telegram-bot.register-webhook', $account))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status.telegram.url_matches', true);

        $this->assertTrue($installation->refresh()->is_enabled);
        $this->assertTrue((bool) $account->telegramBotProfiles()->where('profile', TelegramBotProfile::Customer->value)->value('is_enabled'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook'));

        $this->actingAs($owner)
            ->deleteJson(route('dashboard.accounts.customer-telegram-bot.delete-webhook', $account))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertFalse($installation->refresh()->is_enabled);
        $this->assertFalse((bool) $account->telegramBotProfiles()->where('profile', TelegramBotProfile::Customer->value)->value('is_enabled'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteWebhook'));
    }

    public function test_webhook_controls_cannot_read_or_mutate_another_studios_bot(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$owner, $account] = $this->studioBotFixture('owner_scope_bot');
        [, $otherAccount, $otherInstallation] = $this->studioBotFixture('foreign_scope_bot');

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.customer-telegram-bot.webhook-status', $otherAccount))
            ->assertForbidden();
        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.customer-telegram-bot.register-webhook', $otherAccount))
            ->assertForbidden();
        $this->actingAs($owner)
            ->deleteJson(route('dashboard.accounts.customer-telegram-bot.delete-webhook', $otherAccount))
            ->assertForbidden();

        $this->assertTrue($otherInstallation->refresh()->is_enabled);
        Http::assertNothingSent();
        $this->assertNotSame($account->id, $otherAccount->id);
    }

    /**
     * @return array{User, Account, TelegramBotInstallation}
     */
    private function studioBotFixture(string $username): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $installation = TelegramBotInstallation::factory()->for($account)->create([
            'profile' => TelegramBotProfile::Customer->value,
            'bot_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'bot_username' => $username,
            'webhook_url' => 'https://ladna.local/api/v1/telegram/webhooks/'.fake()->unique()->slug(),
        ]);

        return [$owner, $account, $installation];
    }

    private function authorization(
        Account $account,
        TelegramBotInstallation $installation,
        Customer $customer,
        string $chatId,
    ): TelegramChatAuthorization {
        return TelegramChatAuthorization::factory()
            ->for($account)
            ->for($installation, 'installation')
            ->for($customer)
            ->create([
                'user_id' => null,
                'profile' => TelegramBotProfile::Customer->value,
                'telegram_chat_id' => $chatId,
                'telegram_user_id' => $chatId,
                'status' => TelegramChatAuthorizationStatus::Authorized->value,
            ]);
    }
}
