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
            ->assertSee('Monopay')
            ->assertSee('LiqPay');

        $this->actingAs($platformAdmin)
            ->put(route('platform.integrations.update', 'monopay'), [
                'is_enabled' => '1',
                'credentials' => [
                    'api_token' => 'mono-platform-secret',
                    'payment_type' => 'hold',
                    'invoice_validity_seconds' => 3600,
                ],
            ])
            ->assertRedirect(route('platform.integrations.index', ['tab' => 'payment']));

        $setting = IntegrationSetting::platform()->where('provider', 'monopay')->firstOrFail();

        $this->assertTrue($setting->is_enabled);
        $this->assertSame(IntegrationScope::Platform, $setting->scope_type);
        $this->assertNull($setting->account_id);
        $this->assertSame('mono-platform-secret', $setting->credentials['api_token']);
        $this->assertSame('hold', $setting->credentials['payment_type']);
        $this->assertSame(3600, $setting->credentials['invoice_validity_seconds']);
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
                    'payment_type' => 'hold',
                    'invoice_validity_seconds' => 3600,
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'payment']));

        $setting->refresh();

        $this->assertSame('new-secret', $setting->credentials['api_token']);
        $this->assertSame('hold', $setting->credentials['payment_type']);
        $this->assertSame(3600, $setting->credentials['invoice_validity_seconds']);
    }
}
