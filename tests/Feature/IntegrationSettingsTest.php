<?php

namespace Tests\Feature;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationScope;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\Sms\SmsServiceSettings;
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

    public function test_checkbox_uses_only_license_login_and_password(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.integrations.index', ['tab' => 'fiscalization']))
            ->assertOk()
            ->assertSee('name="credentials[license_key]"', false)
            ->assertSee('name="credentials[cashier_login]"', false)
            ->assertSee('name="credentials[cashier_password]"', false)
            ->assertDontSee('name="credentials[cashier_pin_code]"', false)
            ->assertDontSee('name="credentials[client_name]"', false)
            ->assertDontSee('name="credentials[client_version]"', false);

        $this->actingAs($platformAdmin)
            ->put(route('platform.integrations.update', 'checkbox'), [
                'is_enabled' => '1',
                'credentials' => [
                    'license_key' => 'checkbox-license',
                    'cashier_login' => 'cashier-login',
                    'cashier_password' => 'cashier-password',
                    'cashier_pin_code' => '1234',
                    'client_name' => 'Custom client',
                    'client_version' => '9.9.9',
                ],
            ])
            ->assertRedirect(route('platform.integrations.index', ['tab' => 'fiscalization']));

        $setting = IntegrationSetting::platform()->where('provider', 'checkbox')->firstOrFail();

        $this->assertTrue($setting->is_enabled);
        $this->assertSame([
            'license_key' => 'checkbox-license',
            'cashier_login' => 'cashier-login',
            'cashier_password' => 'cashier-password',
        ], $setting->credentials);
    }

    public function test_checkbox_requires_all_three_credentials_when_enabled(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->put(route('platform.integrations.update', 'checkbox'), [
                'is_enabled' => '1',
                'credentials' => [],
            ])
            ->assertSessionHasErrors([
                'credentials.license_key',
                'credentials.cashier_login',
                'credentials.cashier_password',
            ]);

        $this->assertFalse(IntegrationSetting::platform()->where('provider', 'checkbox')->exists());
    }

    public function test_checkbox_preserves_license_and_removes_legacy_credentials_when_updated(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $setting = IntegrationSetting::factory()->create([
            'provider' => 'checkbox',
            'category' => 'fiscalization',
            'is_enabled' => true,
            'credentials' => [
                'license_key' => 'existing-license',
                'cashier_pin_code' => '1234',
                'client_name' => 'Legacy client',
                'client_version' => '1.0',
            ],
        ]);

        $this->actingAs($platformAdmin)
            ->put(route('platform.integrations.update', 'checkbox'), [
                'is_enabled' => '1',
                'credentials' => [
                    'license_key' => '',
                    'cashier_login' => 'cashier-login',
                    'cashier_password' => 'cashier-password',
                ],
            ])
            ->assertRedirect(route('platform.integrations.index', ['tab' => 'fiscalization']));

        $setting->refresh();

        $this->assertSame([
            'license_key' => 'existing-license',
            'cashier_login' => 'cashier-login',
            'cashier_password' => 'cashier-password',
        ], $setting->credentials);
    }

    public function test_platform_admin_can_configure_email_delivery_engine(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.integrations.index', ['tab' => 'email']))
            ->assertOk()
            ->assertSee('Email delivery')
            ->assertSee('name="credentials[engine]"', false)
            ->assertSee('SendPulse API')
            ->assertSee('SendPulse SMTP')
            ->assertSee('name="credentials[sendpulse_api_key]"', false)
            ->assertSee(route('platform.integrations.index', ['tab' => 'email']), false);

        $this->actingAs($platformAdmin)
            ->put(route('platform.integrations.update', 'mail_delivery'), [
                'is_enabled' => '1',
                'credentials' => [
                    'engine' => 'sendpulse_api',
                    'fallback_engine' => 'log',
                    'mail_from_email' => 'mail@ladna.example',
                    'mail_from_name' => 'Ladna Mail',
                    'sendpulse_api_key' => 'sendpulse-email-api-key',
                    'smtp_login' => 'must-not-be-stored',
                    'smtp_password' => 'must-not-be-stored',
                ],
            ])
            ->assertRedirect(route('platform.integrations.index', ['tab' => 'email']));

        $setting = IntegrationSetting::platform()->where('provider', 'mail_delivery')->firstOrFail();

        $this->assertTrue($setting->is_enabled);
        $this->assertSame('email', $setting->category->value);
        $this->assertSame('sendpulse_api', $setting->credentials['engine']);
        $this->assertSame('log', $setting->credentials['fallback_engine']);
        $this->assertSame('mail@ladna.example', $setting->credentials['mail_from_email']);
        $this->assertSame('Ladna Mail', $setting->credentials['mail_from_name']);
        $this->assertSame('sendpulse-email-api-key', $setting->credentials['sendpulse_api_key']);
        $this->assertArrayNotHasKey('smtp_host', $setting->credentials);
        $this->assertArrayNotHasKey('smtp_port', $setting->credentials);
        $this->assertArrayNotHasKey('smtp_login', $setting->credentials);
        $this->assertArrayNotHasKey('smtp_password', $setting->credentials);
        $this->assertArrayNotHasKey('smtp_encryption', $setting->credentials);

        $rawCredentials = DB::table((new IntegrationSetting)->getTable())
            ->where('id', $setting->id)
            ->value('credentials');

        $this->assertIsString($rawCredentials);
        $this->assertStringNotContainsString('sendpulse-email-api-key', $rawCredentials);
    }

    public function test_normal_studio_owner_cannot_access_platform_integrations(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('platform.integrations.index'))
            ->assertForbidden();

        $this->put(route('platform.integrations.central-sms-provider.update'), [
            'central_sms_provider' => 'smsclub',
        ])->assertForbidden();
    }

    public function test_platform_admin_can_select_the_configured_central_sms_provider(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $this->createPlatformSmsIntegration('turbosms', [
            'api_token' => 'turbo-secret',
            'sms_sender' => 'Ladna',
        ]);
        $smsclub = $this->createPlatformSmsIntegration('smsclub', [
            'bearer_token' => 'smsclub-secret',
            'src_addr' => 'Ladna',
        ]);
        SystemSetting::setValue(SystemSetting::CentralSmsProviderKey, 'turbosms');
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceCentsKey, '12345');
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceCurrencyKey, 'UAH');
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceCheckedAtKey, now()->toIso8601String());
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceErrorKey, 'stale error');

        $this->actingAs($platformAdmin)
            ->get(route('platform.integrations.index', ['tab' => 'messaging']))
            ->assertOk()
            ->assertSee(__('app.central_sms_provider'))
            ->assertSee('name="central_sms_provider"', false)
            ->assertSee(__('app.select'))
            ->assertDontSee('app.choose')
            ->assertDontSee('falls back to TurboSMS')
            ->assertDontSee('Ladna currently falls back');

        $this->assertSame(
            'turbosms',
            app(CustomerAuthAvailability::class)->platformSmsSetting()?->provider->value,
        );

        $this->put(route('platform.integrations.central-sms-provider.update'), [
            'central_sms_provider' => 'smsclub',
        ])->assertRedirect(route('platform.integrations.index', ['tab' => 'messaging']));

        $this->assertSame(
            'smsclub',
            SystemSetting::stringValue(SystemSetting::CentralSmsProviderKey),
        );
        $this->assertSame(
            'smsclub',
            app(CustomerAuthAvailability::class)->platformSmsSetting()?->provider->value,
        );
        $this->assertSame([
            'amount_cents' => null,
            'currency' => null,
            'checked_at' => null,
            'error' => null,
        ], app(SmsServiceSettings::class)->providerBalanceStatus());

        $smsclub->forceFill(['is_enabled' => false])->save();

        $this->assertNull(app(CustomerAuthAvailability::class)->platformSmsSetting());
    }

    public function test_central_sms_provider_must_be_a_configured_platform_messaging_integration(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $this->createPlatformSmsIntegration('smsclub', [
            'bearer_token' => 'smsclub-secret',
            'src_addr' => 'Ladna',
        ], enabled: false);

        $this->actingAs($platformAdmin)
            ->from(route('platform.integrations.index', ['tab' => 'messaging']))
            ->put(route('platform.integrations.central-sms-provider.update'), [
                'central_sms_provider' => 'smsclub',
            ])
            ->assertRedirect(route('platform.integrations.index', ['tab' => 'messaging']))
            ->assertSessionHasErrors('central_sms_provider');

        $this->put(route('platform.integrations.central-sms-provider.update'), [
            'central_sms_provider' => 'monopay',
        ])->assertSessionHasErrors('central_sms_provider');

        $this->assertNull(SystemSetting::stringValue(SystemSetting::CentralSmsProviderKey));
    }

    public function test_updating_the_active_central_sms_integration_clears_cached_provider_health(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $this->createPlatformSmsIntegration('smsclub', [
            'bearer_token' => 'old-secret',
            'src_addr' => 'Ladna',
        ]);
        SystemSetting::setValue(SystemSetting::CentralSmsProviderKey, 'smsclub');
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceCentsKey, '12345');
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceCurrencyKey, 'UAH');
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceCheckedAtKey, now()->toIso8601String());
        SystemSetting::setValue(SmsServiceSettings::ProviderBalanceErrorKey, 'stale error');

        $this->actingAs($platformAdmin)
            ->put(route('platform.integrations.update', 'smsclub'), [
                'is_enabled' => '1',
                'credentials' => [
                    'bearer_token' => 'new-secret',
                    'src_addr' => 'NewLadna',
                ],
            ])
            ->assertRedirect(route('platform.integrations.index', ['tab' => 'messaging']));

        $this->assertSame([
            'amount_cents' => null,
            'currency' => null,
            'checked_at' => null,
            'error' => null,
        ], app(SmsServiceSettings::class)->providerBalanceStatus());
    }

    public function test_account_owner_can_view_and_update_own_account_integrations(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $account->customerAuthSetting()->create([
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => 'turbosms',
        ]);

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

    public function test_sendpulse_messaging_uses_only_api_key_and_sender_name(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $account->customerAuthSetting()->create([
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => 'sendpulse',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging']))
            ->assertOk()
            ->assertSee('name="credentials[api_key]"', false)
            ->assertSee('name="credentials[sms_sender]"', false)
            ->assertDontSee('name="credentials[auth_mode]"', false)
            ->assertDontSee('name="credentials[client_id]"', false)
            ->assertDontSee('name="credentials[client_secret]"', false)
            ->assertDontSee('name="credentials[smtp_host]"', false)
            ->assertDontSee('name="credentials[mail_from_email]"', false)
            ->assertDontSee('name="credentials[sms_route]"', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.integrations.update', [$account, 'sendpulse']), [
                'is_enabled' => '1',
                'credentials' => [
                    'api_key' => 'sendpulse-sms-api-key',
                    'sms_sender' => 'Ladna Studio',
                    'auth_mode' => 'oauth',
                    'client_id' => 'legacy-client-id',
                    'smtp_host' => 'legacy-smtp-host',
                    'mail_from_email' => 'legacy@example.com',
                    'sms_route' => 'legacy-route',
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging']));

        $setting = IntegrationSetting::forAccount($account)
            ->where('provider', 'sendpulse')
            ->firstOrFail();

        $this->assertSame([
            'api_key' => 'sendpulse-sms-api-key',
            'sms_sender' => 'Ladna Studio',
        ], $setting->credentials);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function createPlatformSmsIntegration(string $provider, array $credentials, bool $enabled = true): IntegrationSetting
    {
        return IntegrationSetting::factory()->create([
            'provider' => $provider,
            'category' => IntegrationCategory::Messaging->value,
            'is_enabled' => $enabled,
            'credentials' => $credentials,
        ]);
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
