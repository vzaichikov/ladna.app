<?php

namespace Tests\Feature;

use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramBotProfileSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountAiTelegramSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_ai_and_telegram_settings_tab(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'ai']))
            ->assertOk()
            ->assertSee(__('app.ai_and_telegram'))
            ->assertSee(__('app.customer_telegram_bot_settings'))
            ->assertDontSee(__('app.ai_provider_openai_api_key'))
            ->assertDontSee(__('app.telegram_bot_profile_owner'));
    }

    public function test_owner_can_save_customer_telegram_settings(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.ai-telegram-settings.update', $account), [
                'telegram_profiles' => [
                    TelegramBotProfile::Customer->value => [
                        'enabled' => '1',
                        'mode' => TelegramBotMode::Simple->value,
                        'welcome_message' => 'Customer welcome',
                    ],
                ],
                'telegram_bots' => [
                    TelegramBotProfile::Customer->value => [
                        'token' => '123456:customer-token',
                        'bot_username' => 'customer_ladna_bot',
                    ],
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'ai']))
            ->assertSessionHas('status', __('app.ai_telegram_settings_updated'));

        $installation = TelegramBotInstallation::whereBelongsTo($account)
            ->where('profile', TelegramBotProfile::Customer->value)
            ->firstOrFail();

        $this->assertTrue($installation->is_enabled);
        $this->assertSame('customer-token', substr((string) $installation->tokenValue(), -14));
        $this->assertSame('account', $installation->scope_type);
        $this->assertSame($account->id, $installation->scope_id);
        $this->assertStringContainsString('/api/v1/telegram/webhooks/', (string) $installation->webhook_url);

        $this->assertDatabaseHas('telegram_bot_profiles', [
            'account_id' => $account->id,
            'profile' => TelegramBotProfile::Customer->value,
            'mode' => TelegramBotMode::Simple->value,
            'is_enabled' => true,
        ]);
    }

    public function test_enabled_customer_telegram_profile_requires_token(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'ai']))
            ->put(route('dashboard.accounts.ai-telegram-settings.update', $account), [
                'telegram_profiles' => [
                    TelegramBotProfile::Customer->value => [
                        'enabled' => '1',
                        'mode' => TelegramBotMode::Simple->value,
                    ],
                ],
                'telegram_bots' => [
                    TelegramBotProfile::Customer->value => [
                        'token' => '',
                    ],
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'ai']))
            ->assertSessionHasErrors('telegram_bots.customer.token');

        $this->assertFalse(TelegramBotProfileSetting::whereBelongsTo($account)->exists());
    }
}
