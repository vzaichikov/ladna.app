<?php

namespace Tests\Feature;

use App\Enums\IntegrationScope;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SmsSendingSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sms_sending_is_disabled_by_default_and_gateway_forms_are_hidden(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging']))
            ->assertOk()
            ->assertSee('name="sms_sending_mode"', false)
            ->assertSee(__('app.sms_sending_mode_disabled'))
            ->assertSee(__('app.sms_sending_mode_ladna_service'))
            ->assertSee(__('app.sms_sending_mode_own_gateway'))
            ->assertSee(__('app.select'))
            ->assertDontSee('app.choose')
            ->assertSee('data-sms-own-gateway-settings', false)
            ->assertSee('class="mt-6 grid gap-5 xl:grid-cols-2 hidden"', false);

        $this->assertFalse($account->customerAuthSetting()->exists());
    }

    public function test_owner_selects_one_own_gateway_and_existing_credentials_are_preserved(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $setting = IntegrationSetting::factory()
            ->forAccountScope($account)
            ->create([
                'provider' => 'smsclub',
                'category' => 'messaging',
                'is_enabled' => true,
                'credentials' => [
                    'bearer_token' => 'saved-secret',
                    'src_addr' => 'Studio',
                ],
            ]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.integrations.sms-sending.update', $account), [
                'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
                'sms_provider' => 'smsclub',
            ])
            ->assertRedirect(route('dashboard.accounts.integrations.index', [
                'account' => $account,
                'tab' => 'messaging',
            ]));

        $this->assertDatabaseHas('customer_auth_settings', [
            'account_id' => $account->id,
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => 'smsclub',
        ]);
        $this->assertSame('saved-secret', $setting->fresh()->credentials['bearer_token']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging']))
            ->assertOk()
            ->assertSee('name="sms_provider"', false)
            ->assertSee('name="credentials[bearer_token]"', false)
            ->assertSee('name="credentials[api_token]"', false)
            ->assertDontSee('class="mt-6 grid gap-5 xl:grid-cols-2 hidden"', false);
    }

    public function test_own_gateway_requires_a_provider_and_ladna_mode_clears_it(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $account->customerAuthSetting()->create([
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => 'smsclub',
        ]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.integrations.sms-sending.update', $account), [
                'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
                'sms_provider' => null,
            ])
            ->assertSessionHasErrors('sms_provider');

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.integrations.sms-sending.update', $account), [
                'sms_sending_mode' => SmsSendingMode::LadnaService->value,
                'sms_provider' => 'turbosms',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_auth_settings', [
            'account_id' => $account->id,
            'sms_sending_mode' => SmsSendingMode::LadnaService->value,
            'sms_provider' => null,
        ]);
    }

    public function test_owner_cannot_change_another_accounts_sms_source(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.integrations.sms-sending.update', $otherAccount), [
                'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
                'sms_provider' => 'smsclub',
            ])
            ->assertForbidden();

        $this->assertFalse($otherAccount->customerAuthSetting()->exists());
    }

    public function test_platform_admin_uses_the_same_unified_sms_settings(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create();

        $this->actingAs($platformAdmin)
            ->put(route('platform.accounts.customer-auth.update', $account), [
                'allow_otp' => '1',
                'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
                'sms_provider' => 'sendpulse',
            ])
            ->assertRedirect(route('platform.accounts.customer-auth.edit', $account));

        $this->assertDatabaseHas('customer_auth_settings', [
            'account_id' => $account->id,
            'allow_otp' => true,
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => 'sendpulse',
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('platform.accounts.customer-auth.edit', $account))
            ->assertOk()
            ->assertSee('name="sms_sending_mode"', false)
            ->assertSee('name="sms_provider"', false)
            ->assertSee(__('app.select'))
            ->assertDontSee('app.choose')
            ->assertDontSee('name="otp_sender_scope"', false)
            ->assertDontSee('name="customer_sms_sender_scope"', false);
    }

    public function test_integrations_remain_account_scoped(): void
    {
        $account = Account::factory()->create();

        $integration = IntegrationSetting::factory()
            ->forAccountScope($account)
            ->create([
                'scope_type' => IntegrationScope::Account->value,
                'provider' => 'smsclub',
                'category' => 'messaging',
            ]);

        $this->assertSame($account->id, $integration->account_id);
        $this->assertSame($account->id, $integration->scope_id);
    }
}
