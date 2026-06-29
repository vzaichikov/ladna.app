<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\TelegramBotInstallation;
use App\Models\User;
use App\Support\AccountActivityLogSettings;
use App\Support\SystemAppearance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_access_platform_and_see_all_accounts(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        Account::factory()->create(['name' => 'Studio A']);
        Account::factory()->create(['name' => 'Studio B']);

        $this->actingAs($platformAdmin)
            ->get(route('platform.accounts.index'))
            ->assertOk()
            ->assertSee('Studio A')
            ->assertSee('Studio B');
    }

    public function test_normal_owner_cannot_access_platform(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('platform.index'))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('platform.account.edit'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_update_own_account_profile(): void
    {
        Storage::fake('public');

        $platformAdmin = User::factory()->platformAdmin()->create([
            'name' => 'Old Admin',
            'email' => 'old-platform-owner@example.test',
            'phone' => null,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('platform.account.edit'))
            ->assertOk()
            ->assertSee(__('app.account'))
            ->assertSee('old-platform-owner@example.test');

        $this->actingAs($platformAdmin)
            ->put(route('platform.account.update'), [
                'name' => 'Product Owner',
                'email' => 'product-owner@example.com',
                'phone' => '+380671112244',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
                'avatar' => UploadedFile::fake()->image('platform-avatar.png', 256, 256),
            ])
            ->assertRedirect(route('platform.account.edit'));

        $platformAdmin->refresh();

        $this->assertSame('Product Owner', $platformAdmin->name);
        $this->assertSame('product-owner@example.com', $platformAdmin->email);
        $this->assertSame('+380671112244', $platformAdmin->phone);
        $this->assertTrue(Hash::check('new-password', $platformAdmin->password));
        $this->assertNotNull($platformAdmin->avatar_path);
        Storage::disk('public')->assertExists($platformAdmin->avatar_path);
    }

    public function test_platform_admin_can_update_system_font(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.settings.edit'))
            ->assertOk()
            ->assertSee('Manrope')
            ->assertSee('data-platform-settings-tabs', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('role="tab"', false)
            ->assertSee('data-platform-settings-panel="appearance"', false)
            ->assertSee('form="platform-settings-form"', false)
            ->assertSee(__('app.save'));

        $this->actingAs($platformAdmin)
            ->put(route('platform.settings.update'), [
                'font_family' => 'rubik',
                'settings_tab' => 'appearance',
            ])
            ->assertRedirect(route('platform.settings.edit', ['tab' => 'appearance']));

        $this->assertSame('rubik', SystemSetting::stringValue(SystemAppearance::FontSettingKey));
    }

    public function test_platform_settings_tabs_select_requested_tab(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.settings.edit', ['tab' => 'support']))
            ->assertOk()
            ->assertSee('data-active-tab="support"', false)
            ->assertSee('id="platform-settings-tab-support"', false)
            ->assertSee('aria-controls="support"', false)
            ->assertSee('data-platform-settings-panel="support"', false);
    }

    public function test_platform_admin_can_update_activity_log_settings(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.settings.edit'))
            ->assertOk()
            ->assertSee(__('app.system_activity_log_settings'))
            ->assertSee('name="activity_log_enabled"', false)
            ->assertSee('name="activity_log_retention_days"', false);

        $this->actingAs($platformAdmin)
            ->put(route('platform.settings.update'), [
                'font_family' => SystemAppearance::currentFontKey(),
                'support_url' => null,
                'activity_log_enabled' => '0',
                'activity_log_retention_days' => '45',
                'settings_tab' => 'activity-log',
            ])
            ->assertRedirect(route('platform.settings.edit', ['tab' => 'activity-log']));

        $this->assertFalse(AccountActivityLogSettings::enabled());
        $this->assertSame(45, AccountActivityLogSettings::retentionDays());
    }

    public function test_platform_admin_can_update_global_ai_and_owner_bot_settings(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        PlatformAiSetting::query()->delete();
        PlatformAiProviderCredential::query()->delete();

        $this->actingAs($platformAdmin)
            ->get(route('platform.settings.edit', ['tab' => 'ai-owner']))
            ->assertOk()
            ->assertSee(__('app.platform_ai_owner_bot'))
            ->assertSee('name="owner_ai_assistant_enabled"', false)
            ->assertSee('name="owner_telegram_bot_token"', false);

        $this->actingAs($platformAdmin)
            ->put(route('platform.settings.update'), [
                'font_family' => SystemAppearance::currentFontKey(),
                'support_url' => null,
                'owner_ai_assistant_enabled' => '1',
                'ai_active_provider' => AiProvider::OllamaCloud->value,
                'ai_bot_display_name' => 'Ladna coach',
                'ai_internal_instructions' => 'Answer only about studio work.',
                'ai_provider_models' => [
                    AiProvider::OllamaCloud->value => 'gemma3:27b-cloud',
                ],
                'ai_provider_credentials' => [
                    AiProvider::OllamaCloud->value => 'ollama-secret',
                ],
                'owner_telegram_bot_enabled' => '1',
                'owner_telegram_bot_username' => 'ladna_owner_bot',
                'owner_telegram_bot_token' => '123456:owner-secret',
                'settings_tab' => 'ai-owner',
            ])
            ->assertRedirect(route('platform.settings.edit', ['tab' => 'ai-owner']));

        $setting = PlatformAiSetting::query()->firstOrFail();
        $this->assertTrue($setting->owner_ai_assistant_enabled);
        $this->assertSame(AiProvider::OllamaCloud, $setting->active_provider);
        $this->assertSame('gemma3:27b-cloud', $setting->active_model);
        $this->assertSame('Ladna coach', $setting->bot_display_name);

        $credential = PlatformAiProviderCredential::where('provider', AiProvider::OllamaCloud->value)->firstOrFail();
        $this->assertSame('ollama-secret', $credential->apiKey());

        $installation = TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('scope_id', 0)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->firstOrFail();

        $this->assertNull($installation->account_id);
        $this->assertTrue($installation->is_enabled);
        $this->assertStringContainsString('/api/v1/telegram/webhooks/', (string) $installation->webhook_url);
        $this->assertSame('owner-secret', substr((string) $installation->tokenValue(), -12));
    }

    public function test_platform_admin_can_suspend_account_without_deleting_tenant_data(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create(['status' => 'active']);

        $this->actingAs($platformAdmin)
            ->put(route('platform.accounts.update', $account), [
                'name' => $account->name,
                'slug' => $account->slug,
                'status' => 'suspended',
                'default_language' => 'uk',
                'default_currency' => 'UAH',
                'timezone' => 'Europe/Kyiv',
                'subscription_plan_id' => null,
                'subscription_status' => 'suspended',
            ])
            ->assertRedirect(route('platform.accounts.show', $account));

        $account->refresh();
        $this->assertSame('suspended', $account->status->value);
        $this->assertModelExists($account);
    }

    public function test_platform_admin_creates_studio_account_with_initial_owner(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $plan = SubscriptionPlan::factory()->create();

        $this->actingAs($platformAdmin)
            ->post(route('platform.accounts.store'), [
                'name' => 'Charmpole',
                'slug' => 'charmpole-test',
                'status' => 'active',
                'default_language' => 'uk',
                'default_currency' => 'UAH',
                'brand_color' => '#d80a7d',
                'timezone' => 'Europe/Kyiv',
                'subscription_plan_id' => $plan->id,
                'subscription_status' => 'active',
                'owner_name' => 'Настя',
                'owner_email' => 'nastya-owner@example.com',
                'owner_password' => 'password',
            ])
            ->assertRedirect();

        $account = Account::where('slug', 'charmpole-test')->firstOrFail();
        $owner = User::where('email', 'nastya-owner@example.com')->firstOrFail();

        $this->assertTrue($account->isAccessibleBy($owner));
        $this->assertTrue($account->memberships()
            ->whereBelongsTo($owner)
            ->where('role', 'owner')
            ->exists());
        $this->assertSame($plan->id, $account->subscription?->subscription_plan_id);
    }

    public function test_platform_admin_deletes_studio_and_only_dedicated_owner_user(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $dedicatedOwner = User::factory()->create(['email' => 'dedicated-owner@example.com']);
        $sharedOwner = User::factory()->create(['email' => 'shared-owner@example.com']);
        $account = Account::factory()->create(['name' => 'Disposable Studio']);
        $otherAccount = Account::factory()->create(['name' => 'Other Studio']);
        $account->addOwner($dedicatedOwner);
        $account->addOwner($sharedOwner);
        $otherAccount->addOwner($sharedOwner);

        $this->actingAs($platformAdmin)
            ->delete(route('platform.accounts.destroy', $account))
            ->assertRedirect(route('platform.accounts.index'))
            ->assertSessionHas('status', __('app.account_deleted'));

        $this->assertModelMissing($account);
        $this->assertModelMissing($dedicatedOwner);
        $this->assertModelExists($sharedOwner);
        $this->assertTrue($otherAccount->fresh()->isAccessibleBy($sharedOwner));
    }
}
