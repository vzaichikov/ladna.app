<?php

namespace Tests\Feature;

use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_view_and_update_platform_integrations(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.integrations.index'))
            ->assertOk()
            ->assertSee(__('app.integration_category_authentication'), false)
            ->assertSee(__('app.integration_category_email'), false)
            ->assertSee('Monopay')
            ->assertSee('LiqPay')
            ->assertDontSee('credentials[payment_type]', false)
            ->assertDontSee('credentials[submerchant_code]', false)
            ->assertDontSee('credentials[webhook_public_key]', false);

        $this->actingAs($platformAdmin)
            ->put(route('platform.integrations.update', 'monopay'), [
                'is_enabled' => '1',
                'credentials' => [
                    'api_token' => 'mono-platform-secret',
                    'invoice_validity_seconds' => 3600,
                ],
            ])
            ->assertRedirect(route('platform.integrations.index', ['tab' => 'payment']));

        $setting = IntegrationSetting::platform()->where('provider', 'monopay')->firstOrFail();

        $this->assertTrue($setting->is_enabled);
        $this->assertSame(IntegrationScope::Platform, $setting->scope_type);
        $this->assertNull($setting->account_id);
        $this->assertSame('mono-platform-secret', $setting->credentials['api_token']);
        $this->assertSame(3600, $setting->credentials['invoice_validity_seconds']);
        $this->assertArrayNotHasKey('payment_type', $setting->credentials);
        $this->assertArrayNotHasKey('submerchant_code', $setting->credentials);
        $this->assertArrayNotHasKey('webhook_public_key', $setting->credentials);
    }

    public function test_platform_admin_can_configure_email_delivery_engine(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.integrations.index', ['tab' => 'email']))
            ->assertOk()
            ->assertSee('Email delivery')
            ->assertSee('name="credentials[engine]"', false)
            ->assertSee('SendPulse SMTP')
            ->assertSee(route('platform.integrations.index', ['tab' => 'email']), false);

        $this->actingAs($platformAdmin)
            ->put(route('platform.integrations.update', 'mail_delivery'), [
                'is_enabled' => '1',
                'credentials' => [
                    'engine' => 'sendpulse_smtp',
                    'fallback_engine' => 'log',
                    'mail_from_email' => 'studio@example.com',
                    'mail_from_name' => 'Ladna Mail',
                    'smtp_host' => 'smtp-pulse.com',
                    'smtp_port' => 587,
                    'smtp_login' => 'smtp-user',
                    'smtp_password' => 'smtp-secret',
                    'smtp_encryption' => 'tls',
                ],
            ])
            ->assertRedirect(route('platform.integrations.index', ['tab' => 'email']));

        $setting = IntegrationSetting::platform()->where('provider', 'mail_delivery')->firstOrFail();

        $this->assertTrue($setting->is_enabled);
        $this->assertSame('email', $setting->category->value);
        $this->assertSame('sendpulse_smtp', $setting->credentials['engine']);
        $this->assertSame('log', $setting->credentials['fallback_engine']);
        $this->assertSame('studio@example.com', $setting->credentials['mail_from_email']);
        $this->assertSame('Ladna Mail', $setting->credentials['mail_from_name']);
        $this->assertSame('smtp-pulse.com', $setting->credentials['smtp_host']);
        $this->assertSame(587, $setting->credentials['smtp_port']);
        $this->assertSame('smtp-user', $setting->credentials['smtp_login']);
        $this->assertSame('smtp-secret', $setting->credentials['smtp_password']);
    }

    public function test_normal_studio_owner_cannot_access_platform_integrations(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('platform.integrations.index'))
            ->assertForbidden();
    }

    public function test_account_owner_can_view_and_update_own_account_integrations(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging']))
            ->assertOk()
            ->assertDontSee(__('app.integration_category_authentication'), false)
            ->assertDontSee('Email delivery')
            ->assertDontSee('mail_delivery')
            ->assertSee('TurboSMS')
            ->assertSee('SendPulse');

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.integrations.update', [$account, 'turbosms']), [
                'is_enabled' => '1',
                'credentials' => [
                    'api_token' => 'turbo-secret',
                    'sms_sender' => 'CharmCRM',
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging']));

        $setting = IntegrationSetting::forAccount($account)->where('provider', 'turbosms')->firstOrFail();

        $this->assertTrue($setting->is_enabled);
        $this->assertSame(IntegrationScope::Account, $setting->scope_type);
        $this->assertSame($account->id, $setting->scope_id);
        $this->assertSame($account->id, $setting->account_id);
        $this->assertSame('turbo-secret', $setting->credentials['api_token']);
        $this->assertSame('CharmCRM', $setting->credentials['sms_sender']);
    }

    public function test_account_integrations_do_not_show_empty_authentication_category(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'authentication']))
            ->assertOk()
            ->assertDontSee(__('app.integration_category_authentication'), false)
            ->assertDontSee('Google OAuth')
            ->assertDontSee('Cloudflare Turnstile')
            ->assertSee('Monopay');
    }

    public function test_account_owner_cannot_access_another_accounts_integrations(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $otherAccount->addOwner($otherOwner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.index', $otherAccount))
            ->assertForbidden();

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.integrations.update', [$otherAccount, 'smsclub']), [
                'is_enabled' => '1',
                'credentials' => [
                    'bearer_token' => 'smsclub-secret',
                    'src_addr' => 'CharmCRM',
                ],
            ])
            ->assertForbidden();

        $this->assertFalse(IntegrationSetting::forAccount($otherAccount)->where('provider', 'smsclub')->exists());
    }

    public function test_blank_secret_fields_preserve_existing_values_and_filled_fields_replace_them(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        IntegrationSetting::factory()
            ->forAccountScope($account)
            ->create([
                'provider' => 'turbosms',
                'category' => 'messaging',
                'is_enabled' => true,
                'credentials' => [
                    'api_token' => 'old-secret',
                    'sms_sender' => 'OldSender',
                ],
            ]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.integrations.update', [$account, 'turbosms']), [
                'is_enabled' => '1',
                'credentials' => [
                    'api_token' => '',
                    'sms_sender' => 'NewSender',
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging']));

        $setting = IntegrationSetting::forAccount($account)->where('provider', 'turbosms')->firstOrFail();
        $this->assertSame('old-secret', $setting->credentials['api_token']);
        $this->assertSame('NewSender', $setting->credentials['sms_sender']);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.integrations.update', [$account, 'turbosms']), [
                'is_enabled' => '1',
                'credentials' => [
                    'api_token' => 'new-secret',
                    'sms_sender' => 'NewSender',
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging']));

        $setting->refresh();
        $this->assertSame('new-secret', $setting->credentials['api_token']);
    }

    public function test_credentials_are_not_stored_as_plain_json(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->put(route('platform.integrations.update', 'liqpay'), [
                'is_enabled' => '1',
                'credentials' => [
                    'public_key' => 'public-key',
                    'private_key' => 'private-secret',
                    'api_version' => 7,
                ],
            ])
            ->assertRedirect(route('platform.integrations.index', ['tab' => 'payment']));

        $setting = IntegrationSetting::platform()->where('provider', 'liqpay')->firstOrFail();
        $rawCredentials = DB::table((new IntegrationSetting)->getTable())
            ->where('id', $setting->id)
            ->value('credentials');

        $this->assertIsString($rawCredentials);
        $this->assertStringNotContainsString('private-secret', $rawCredentials);
        $this->assertSame('private-secret', $setting->credentials['private_key']);
    }

    public function test_integration_page_allows_replacing_credentials_encrypted_with_an_old_key(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $setting = IntegrationSetting::factory()
            ->forAccountScope($account)
            ->create([
                'provider' => 'monopay',
                'category' => 'payment',
                'is_enabled' => false,
                'credentials' => [
                    'api_token' => 'old-secret',
                ],
            ]);

        DB::table((new IntegrationSetting)->getTable())
            ->where('id', $setting->id)
            ->update(['credentials' => 'encrypted-with-another-app-key']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.index', $account))
            ->assertOk()
            ->assertSee(__('app.integration_credentials_unreadable'));

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.integrations.update', [$account, 'monopay']), [
                'is_enabled' => '1',
                'credentials' => [
                    'api_token' => 'new-secret',
                    'invoice_validity_seconds' => 3600,
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'payment']));

        $setting->refresh();

        $this->assertSame('new-secret', $setting->credentials['api_token']);
        $this->assertSame(3600, $setting->credentials['invoice_validity_seconds']);
        $this->assertArrayNotHasKey('payment_type', $setting->credentials);
        $this->assertArrayNotHasKey('submerchant_code', $setting->credentials);
        $this->assertArrayNotHasKey('webhook_public_key', $setting->credentials);
    }
}
