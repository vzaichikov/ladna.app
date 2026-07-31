<?php

namespace Tests\Feature;

use App\Enums\AccountMode;
use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\SmsDelivery;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Sms\SmsServiceSettings;
use App\Support\SystemAppearance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SmsAccountSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_only_owner_can_view_sms_account_and_enable_valid_auto_top_up(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create(['sms_segment_price_cents' => 100]);
        $subscription = AccountSubscription::factory()
            ->for($account)
            ->for($plan, 'plan')
            ->create();
        $account->customerAuthSetting()->create([
            'sms_sending_mode' => SmsSendingMode::LadnaService->value,
        ]);
        AccountSubscriptionPaymentMethod::factory()
            ->for($account)
            ->for($subscription, 'subscription')
            ->create([
                'status' => SubscriptionPaymentMethodStatus::Active->value,
                'provider_card_token' => 'encrypted-card-token',
                'masked_pan' => '4444 **** **** 1111',
                'verified_at' => now(),
            ]);
        SystemSetting::setValue(SmsServiceSettings::EnabledKey, '1');

        $this->actingAs($other)
            ->get(route('dashboard.accounts.sms-account.show', $account))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.sms-account.show', $account))
            ->assertOk()
            ->assertSee(__('app.sms_account'))
            ->assertSee(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'messaging']), false)
            ->assertSee('4444 **** **** 1111');

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.sms-account.auto-top-up.update', $account), [
                'auto_top_up_enabled' => 1,
                'auto_top_up_threshold_uah' => '50.00',
                'auto_top_up_target_uah' => '40.00',
                'auto_top_up_monthly_cap_uah' => '100.00',
            ])
            ->assertSessionHasErrors('auto_top_up_target_uah');

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.sms-account.auto-top-up.update', $account), [
                'auto_top_up_enabled' => 1,
                'auto_top_up_threshold_uah' => '20.00',
                'auto_top_up_target_uah' => '50.00',
                'auto_top_up_monthly_cap_uah' => '200.00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('account_sms_wallets', [
            'account_id' => $account->id,
            'auto_top_up_enabled' => true,
            'auto_top_up_threshold_cents' => 2_000,
            'auto_top_up_target_cents' => 5_000,
            'auto_top_up_monthly_cap_cents' => 20_000,
        ]);
    }

    public function test_platform_adjustment_requires_a_reason_and_is_append_only(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create();

        $this->actingAs($admin)
            ->post(route('platform.accounts.sms-account.adjust', $account), [
                'amount_uah' => '10.00',
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->post(route('platform.accounts.sms-account.adjust', $account), [
                'amount_uah' => '10.00',
                'reason' => 'Support correction',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('account_sms_wallets', [
            'account_id' => $account->id,
            'balance_cents' => 1_000,
        ]);
        $this->assertDatabaseHas('sms_wallet_ledger_entries', [
            'account_id' => $account->id,
            'amount_cents' => 1_000,
            'reason' => 'Support correction',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_platform_admin_can_inspect_sms_service_settings_account_and_delivery_log(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create(['name' => 'SMS Support Studio']);
        $delivery = SmsDelivery::factory()->for($account)->create([
            'purpose' => SmsDeliveryPurpose::CustomerNotification->value,
            'source_mode' => SmsSendingMode::LadnaService->value,
            'status' => SmsDeliveryStatus::Accepted->value,
            'provider' => 'smsclub',
            'provider_message_id' => 'provider-sms-123',
            'estimated_segments' => 2,
            'billed_segments' => 2,
            'sms_segment_price_cents' => 100,
            'amount_cents' => 200,
        ]);

        $this->actingAs($admin)
            ->get(route('platform.settings.edit', ['tab' => 'sms-service']))
            ->assertOk()
            ->assertSee('data-active-tab="sms-service"', false)
            ->assertSee('name="sms_service_enabled"', false)
            ->assertSee('name="sms_top_up_presets_uah[]"', false)
            ->assertSee('name="sms_otp_hourly_limit"', false)
            ->assertSee('name="sms_otp_daily_limit"', false)
            ->assertSee('name="sms_provider_low_balance_uah"', false);

        $this->actingAs($admin)
            ->get(route('platform.accounts.sms-account.show', $account))
            ->assertOk()
            ->assertSee(__('app.sms_wallet_adjustment'))
            ->assertSee('name="reason"', false);

        $this->actingAs($admin)
            ->get(route('platform.sms-deliveries.index', [
                'account_id' => $account->id,
                'purpose' => SmsDeliveryPurpose::CustomerNotification->value,
                'status' => SmsDeliveryStatus::Accepted->value,
                'mode' => SmsSendingMode::LadnaService->value,
                'provider' => 'smsclub',
            ]))
            ->assertOk()
            ->assertSee($account->name)
            ->assertSee($delivery->provider_message_id)
            ->assertSee('name="account_id" class="crm-field"', false)
            ->assertSee('name="purpose" class="crm-field"', false)
            ->assertSee('name="status" class="crm-field"', false)
            ->assertSee('name="mode" class="crm-field"', false)
            ->assertSee('name="provider" class="crm-field"', false)
            ->assertDontSee('crm-select', false);
    }

    public function test_platform_admin_can_update_sms_service_settings(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->put(route('platform.settings.update'), [
                'font_family' => SystemAppearance::currentFontKey(),
                'settings_tab' => 'sms-service',
                'sms_service_enabled' => '1',
                'sms_top_up_presets_uah' => ['50.00', '100.00', '200.00'],
                'sms_otp_hourly_limit' => '60',
                'sms_otp_daily_limit' => '300',
                'sms_provider_low_balance_uah' => '500.00',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('platform.settings.edit', ['tab' => 'sms-service']));

        $this->assertSame('1', SystemSetting::stringValue(SmsServiceSettings::EnabledKey));
        $this->assertSame(
            [5_000, 10_000, 20_000],
            app(SmsServiceSettings::class)->topUpPresetsCents(),
        );
        $this->assertSame(60, app(SmsServiceSettings::class)->otpHourlyLimit());
        $this->assertSame(300, app(SmsServiceSettings::class)->otpDailyLimit());
        $this->assertSame(50_000, app(SmsServiceSettings::class)->providerLowBalanceThresholdCents());
    }

    public function test_read_only_demo_cannot_start_an_sms_top_up_or_card_verification(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['mode' => AccountMode::DemoReadonly->value]);
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create(['sms_segment_price_cents' => 100]);
        AccountSubscription::factory()
            ->for($account)
            ->for($plan, 'plan')
            ->create();
        $account->customerAuthSetting()->create([
            'sms_sending_mode' => SmsSendingMode::LadnaService->value,
        ]);
        SystemSetting::setValue(SmsServiceSettings::EnabledKey, '1');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.sms-account.top-ups.store', $account), [
                'amount_cents' => 5_000,
            ])
            ->assertSessionHasErrors('demo');

        $this->assertDatabaseMissing('account_subscription_payment_methods', [
            'account_id' => $account->id,
        ]);
        $this->assertDatabaseMissing('sms_top_up_payments', [
            'account_id' => $account->id,
        ]);
    }
}
