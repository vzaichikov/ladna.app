<?php

namespace Tests\Feature;

use App\Enums\AccountMode;
use App\Enums\IntegrationCategory;
use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\SmsDelivery;
use App\Models\SmsTopUpPayment;
use App\Models\SmsWalletLedgerEntry;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Sms\SmsServiceSettings;
use App\Support\SystemAppearance;
use Illuminate\Database\Eloquent\Factories\Sequence;
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
            ->assertSee(route('dashboard.accounts.integrations.show', [$account, IntegrationCategory::Messaging]), false)
            ->assertSee('4444 **** **** 1111')
            ->assertDontSee(__('app.sms_first_top_up_card_copy'));

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

    public function test_first_sms_top_up_explains_secure_card_linking_and_shows_presets(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
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
        SystemSetting::setValue(SmsServiceSettings::TopUpPresetsKey, '5000,10000,20000');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.sms-account.show', $account))
            ->assertOk()
            ->assertSee(__('app.sms_first_top_up_card_copy'))
            ->assertSee('name="amount_cents" value="5000"', false)
            ->assertSee('name="amount_cents" value="10000"', false)
            ->assertSee('name="amount_cents" value="20000"', false);
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

    public function test_sms_account_reports_use_tabs_and_independent_pagination(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $wallet = AccountSmsWallet::factory()->for($account)->create();

        SmsWalletLedgerEntry::factory()
            ->count(26)
            ->sequence(fn (Sequence $sequence): array => [
                'reason' => sprintf('Ledger report %02d', $sequence->index + 1),
            ])
            ->create([
                'account_sms_wallet_id' => $wallet->id,
                'account_id' => $account->id,
            ]);
        SmsTopUpPayment::factory()
            ->count(26)
            ->sequence(fn (Sequence $sequence): array => [
                'order_id' => sprintf('SMS-TAB-TOP-UP-%02d', $sequence->index + 1),
            ])
            ->create([
                'account_sms_wallet_id' => $wallet->id,
                'account_id' => $account->id,
            ]);
        SmsDelivery::factory()
            ->count(26)
            ->sequence(fn (Sequence $sequence): array => [
                'provider_message_id' => sprintf('SMS-TAB-DELIVERY-%02d', $sequence->index + 1),
            ])
            ->create([
                'account_sms_wallet_id' => $wallet->id,
                'account_id' => $account->id,
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.sms-account.show', $account))
            ->assertOk()
            ->assertSee('data-active-sms-report="ledger"', false)
            ->assertSee('aria-selected="true"', false)
            ->assertSee('Ledger report 26')
            ->assertDontSee('Ledger report 01')
            ->assertDontSee('SMS-TAB-TOP-UP-26')
            ->assertSee('ledger_page=2', false)
            ->assertSee('tab=ledger', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.sms-account.show', [$account, 'ledger_page' => 2]))
            ->assertOk()
            ->assertSee('data-active-sms-report="ledger"', false)
            ->assertSee('Ledger report 01')
            ->assertDontSee('Ledger report 26');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.sms-account.show', [$account, 'tab' => 'top-ups']))
            ->assertOk()
            ->assertSee('data-active-sms-report="top-ups"', false)
            ->assertSee('SMS-TAB-TOP-UP-26')
            ->assertDontSee('SMS-TAB-TOP-UP-01')
            ->assertDontSee('Ledger report 26')
            ->assertSee('top_ups_page=2', false)
            ->assertSee('tab=top-ups', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.sms-account.show', [$account, 'top_ups_page' => 2]))
            ->assertOk()
            ->assertSee('data-active-sms-report="top-ups"', false)
            ->assertSee('SMS-TAB-TOP-UP-01')
            ->assertDontSee('SMS-TAB-TOP-UP-26');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.sms-account.show', [$account, 'tab' => 'deliveries']))
            ->assertOk()
            ->assertSee('data-active-sms-report="deliveries"', false)
            ->assertSee('SMS-TAB-DELIVERY-26')
            ->assertDontSee('SMS-TAB-DELIVERY-01')
            ->assertDontSee('Ledger report 26')
            ->assertSee('deliveries_page=2', false)
            ->assertSee('tab=deliveries', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.sms-account.show', [$account, 'deliveries_page' => 2]))
            ->assertOk()
            ->assertSee('data-active-sms-report="deliveries"', false)
            ->assertSee('SMS-TAB-DELIVERY-01')
            ->assertDontSee('SMS-TAB-DELIVERY-26');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.sms-account.show', [$account, 'tab' => 'invalid']))
            ->assertOk()
            ->assertSee('data-active-sms-report="ledger"', false);
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
            ->assertSee('name="reason"', false)
            ->assertSee('data-active-sms-report="ledger"', false);

        $this->actingAs($admin)
            ->get(route('platform.accounts.sms-account.show', [$account, 'tab' => 'deliveries']))
            ->assertOk()
            ->assertSee('data-active-sms-report="deliveries"', false)
            ->assertSee($delivery->provider_message_id);

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
