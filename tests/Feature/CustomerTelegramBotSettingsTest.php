<?php

namespace Tests\Feature;

use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\TelegramBotInstallation;
use App\Models\User;
use App\Support\Telegram\CustomerTelegramLinkResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerTelegramBotSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_the_customer_bot_setup_without_exposing_a_token(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'account',
            'scope_id' => $account->id,
            'profile' => TelegramBotProfile::Customer->value,
            'bot_id' => '90001',
            'bot_username' => 'studio_customer_bot',
            'encrypted_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'token_last_four' => 'BCDE',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'telegram']))
            ->assertOk()
            ->assertSee(__('app.customer_telegram_bot_settings'))
            ->assertSee('@studio_customer_bot')
            ->assertSee('••••BCDE')
            ->assertDontSee('123456789:abcdefghijklmnopqrstuvwxyz_ABCDE')
            ->assertDontSee(__('app.ai_provider_openai_api_key'))
            ->assertSee('name="token"', false)
            ->assertSee('data-customer-telegram-settings-layout', false)
            ->assertSee('lg:grid-cols-[minmax(0,3fr)_minmax(22rem,2fr)]', false)
            ->assertSee('data-customer-telegram-share-card', false)
            ->assertSee('data-customer-telegram-placement-settings', false)
            ->assertSee('name="customer_dashboard"', false)
            ->assertSee('name="public_studio"', false)
            ->assertSee('name="public_contacts"', false)
            ->assertDontSee('name="allow_otp"', false);
    }

    public function test_owner_can_save_customer_bot_placements_without_reconnecting_the_bot(): void
    {
        Http::fake();

        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $profile = $account->telegramBotProfiles()->create([
            'profile' => TelegramBotProfile::Customer->value,
            'mode' => TelegramBotMode::Simple->value,
            'is_enabled' => true,
            'settings' => ['future_setting' => 'preserved'],
        ]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.customer-telegram-bot.placements.update', $account), [
                CustomerTelegramLinkResolver::PlacementCustomerDashboard => '1',
                CustomerTelegramLinkResolver::PlacementPublicStudio => '0',
                CustomerTelegramLinkResolver::PlacementPublicContacts => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'telegram']))
            ->assertSessionHas('status', __('app.telegram_bot_placement_settings_saved'));

        $this->assertSame([
            'future_setting' => 'preserved',
            CustomerTelegramLinkResolver::PlacementSettingsKey => [
                CustomerTelegramLinkResolver::PlacementCustomerDashboard => true,
                CustomerTelegramLinkResolver::PlacementPublicStudio => false,
                CustomerTelegramLinkResolver::PlacementPublicContacts => true,
            ],
        ], $profile->refresh()->settings);
        Http::assertNothingSent();

        $outsider = User::factory()->create();
        $this->actingAs($outsider)
            ->put(route('dashboard.accounts.customer-telegram-bot.placements.update', $account), [
                CustomerTelegramLinkResolver::PlacementCustomerDashboard => '0',
                CustomerTelegramLinkResolver::PlacementPublicStudio => '0',
                CustomerTelegramLinkResolver::PlacementPublicContacts => '0',
            ])
            ->assertForbidden();

        $this->assertTrue((bool) data_get(
            $profile->refresh()->settings,
            CustomerTelegramLinkResolver::PlacementSettingsKey.'.'.CustomerTelegramLinkResolver::PlacementCustomerDashboard,
        ));
    }

    public function test_qr_links_page_includes_the_configured_customer_bot(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        TelegramBotInstallation::factory()->for($account)->create([
            'profile' => TelegramBotProfile::Customer->value,
            'bot_username' => '@studio_customer_bot',
        ]);
        $otherAccount = Account::factory()->create();
        TelegramBotInstallation::factory()->for($otherAccount)->create([
            'profile' => TelegramBotProfile::Customer->value,
            'bot_username' => '@other_studio_customer_bot',
        ]);

        $botUrl = 'https://t.me/studio_customer_bot?start=ladna';
        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.qr-links.show', $account))
            ->assertOk()
            ->assertSee(__('app.customer_telegram_bot_menu'))
            ->assertSee(__('app.telegram_bot_share_with_customers_copy'))
            ->assertSee($botUrl, false)
            ->assertDontSee('https://t.me/other_studio_customer_bot?start=ladna', false);

        $this->assertSame(3, substr_count($response->getContent(), 'data-print-section'));
        $this->assertSame(3, substr_count($response->getContent(), 'data-qr-print-poster'));
    }

    public function test_owner_connects_a_verified_studio_bot_and_registers_localized_commands(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/getMe')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['id' => 987654321, 'username' => 'ladna_test_studio_bot'],
                ]);
            }

            return Http::response(['ok' => true, 'result' => []]);
        });

        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $token = '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE';

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.customer-telegram-bot.update', $account), [
                'token' => $token,
                'welcome_message' => 'Welcome to our studio',
            ])
            ->assertRedirect(route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'telegram']))
            ->assertSessionHas('status', __('app.telegram_webhook_registered'));

        $installation = TelegramBotInstallation::whereBelongsTo($account)
            ->where('profile', TelegramBotProfile::Customer->value)
            ->sole();

        $this->assertSame('987654321', $installation->bot_id);
        $this->assertSame('ladna_test_studio_bot', $installation->bot_username);
        $this->assertSame($token, $installation->tokenValue());
        $this->assertSame('BCDE', $installation->token_last_four);
        $this->assertTrue($installation->is_enabled);
        $this->assertStringContainsString('/api/v1/telegram/webhooks/', (string) $installation->webhook_url);
        $this->assertStringNotContainsString($token, (string) $installation->webhook_url);
        $this->assertDatabaseHas('telegram_bot_profiles', [
            'account_id' => $account->id,
            'profile' => TelegramBotProfile::Customer->value,
            'mode' => TelegramBotMode::Simple->value,
            'is_enabled' => true,
            'welcome_message' => 'Welcome to our studio',
        ]);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['allowed_updates'] === ['message', 'callback_query']
            && filled($request['secret_token']));
        Http::assertSentCount(5);
        $commandLanguages = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands'))
            ->map(fn (Request $request): mixed => $request['language_code'])
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['en', 'uk'], $commandLanguages);
    }

    public function test_the_same_telegram_bot_cannot_be_connected_to_two_studios(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['id' => 555001, 'username' => 'shared_studio_bot'],
        ])]);

        TelegramBotInstallation::factory()->create([
            'bot_id' => '555001',
            'profile' => TelegramBotProfile::Customer->value,
        ]);
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.customer-telegram-bot.update', $account), [
                'token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            ])
            ->assertSessionHasErrors(['telegram_bot' => __('app.telegram_bot_already_connected')]);

        $this->assertFalse(TelegramBotInstallation::whereBelongsTo($account)->exists());
    }

    public function test_connect_requires_a_valid_botfather_token(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.customer-telegram-bot.update', $account), [
                'token' => 'not-a-token',
            ])
            ->assertSessionHasErrors('token');

        $this->assertFalse(TelegramBotInstallation::whereBelongsTo($account)->exists());
    }
}
