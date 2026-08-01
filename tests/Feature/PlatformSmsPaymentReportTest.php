<?php

namespace Tests\Feature;

use App\Enums\AccountMode;
use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\FiscalReceiptStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscriptionPayment;
use App\Models\FiscalReceipt;
use App\Models\IntegrationSetting;
use App\Models\SmsTopUpPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlatformSmsPaymentReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_view_operational_studio_sms_payments_and_fiscalization(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'name' => 'SMS Payments Studio',
            'slug' => 'sms-payments-studio',
        ]);
        $account->addOwner($owner);
        $wallet = AccountSmsWallet::factory()->for($account)->create();
        $payment = SmsTopUpPayment::factory()
            ->for($account)
            ->for($wallet, 'wallet')
            ->create([
                'kind' => SmsTopUpKind::Automatic->value,
                'provider' => 'sms-report-provider',
                'status' => SmsTopUpPaymentStatus::PaymentPaid->value,
                'amount_cents' => 15_000,
                'paid_at' => now(),
                'order_id' => 'SMS-PLATFORM-REPORT-PAID',
            ]);
        FiscalReceipt::factory()
            ->forPlatformScope($account)
            ->for($payment, 'payment')
            ->fiscalized('FN-SMS-PLATFORM-1')
            ->create();
        $demoAccount = Account::factory()->create([
            'name' => 'Hidden Demo SMS Studio',
            'mode' => AccountMode::DemoReadonly->value,
        ]);
        $demoWallet = AccountSmsWallet::factory()->for($demoAccount)->create();
        SmsTopUpPayment::factory()
            ->for($demoAccount)
            ->for($demoWallet, 'wallet')
            ->create(['order_id' => 'SMS-HIDDEN-DEMO-PAYMENT']);
        $this->enablePlatformFiscalization();

        $this->actingAs($admin)
            ->get(route('platform.sms-payments.index', ['provider' => 'sms-report-provider']))
            ->assertOk()
            ->assertSee(__('app.sms_payments'))
            ->assertSee('SMS Payments Studio')
            ->assertSee('sms-payments-studio')
            ->assertSee('SMS-PLATFORM-REPORT-PAID')
            ->assertSee(__('app.sms_top_up_kind_automatic'))
            ->assertSee('FN-SMS-PLATFORM-1')
            ->assertDontSee('SMS-HIDDEN-DEMO-PAYMENT')
            ->assertViewHas('stats', fn (array $stats): bool => $stats['total'] === 1
                && $stats['paid_amount_cents'] === 15_000);

        $this->actingAs($owner)
            ->get(route('platform.sms-payments.index'))
            ->assertForbidden();
    }

    public function test_sms_payment_filters_and_pagination_preserve_the_query(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create();
        $wallet = AccountSmsWallet::factory()->for($account)->create();
        SmsTopUpPayment::factory()
            ->count(21)
            ->sequence(fn (Sequence $sequence): array => [
                'order_id' => sprintf('SMS-FILTERED-%02d', $sequence->index + 1),
            ])
            ->for($account)
            ->for($wallet, 'wallet')
            ->create([
                'provider' => IntegrationProvider::Monopay->value,
                'kind' => SmsTopUpKind::Manual->value,
                'status' => SmsTopUpPaymentStatus::PaymentPaid->value,
                'paid_at' => now(),
            ]);
        SmsTopUpPayment::factory()
            ->for($account)
            ->for($wallet, 'wallet')
            ->create([
                'provider' => IntegrationProvider::Monopay->value,
                'kind' => SmsTopUpKind::Automatic->value,
                'status' => SmsTopUpPaymentStatus::PaymentFailed->value,
                'order_id' => 'SMS-NOT-MANUAL-FAILED',
            ]);

        $this->actingAs($admin)
            ->get(route('platform.sms-payments.index', [
                'status' => SmsTopUpPaymentStatus::PaymentPaid->value,
                'provider' => IntegrationProvider::Monopay->value,
                'kind' => SmsTopUpKind::Manual->value,
            ]))
            ->assertOk()
            ->assertSee('SMS-FILTERED-21')
            ->assertDontSee('SMS-FILTERED-01')
            ->assertDontSee('SMS-NOT-MANUAL-FAILED')
            ->assertSee('status=payment_paid', false)
            ->assertSee('provider=monopay', false)
            ->assertSee('kind=manual', false)
            ->assertSee('page=2', false);
    }

    public function test_sms_and_saas_fiscal_failure_metrics_are_isolated(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create();
        $wallet = AccountSmsWallet::factory()->for($account)->create();
        $smsPayment = SmsTopUpPayment::factory()
            ->for($account)
            ->for($wallet, 'wallet')
            ->create();
        $saasPayment = AccountSubscriptionPayment::factory()
            ->for($account)
            ->create(['status' => AccountSubscriptionPaymentStatus::PaymentPaid->value]);
        FiscalReceipt::factory()
            ->forPlatformScope($account)
            ->for($smsPayment, 'payment')
            ->failed('SMS fiscal failure')
            ->create();
        FiscalReceipt::factory()
            ->forPlatformScope($account)
            ->for($saasPayment, 'payment')
            ->failed('SaaS fiscal failure')
            ->create();
        $this->enablePlatformFiscalization();
        $smsFiscalFailures = FiscalReceipt::query()
            ->where('scope_type', IntegrationScope::Platform->value)
            ->where('scope_id', 0)
            ->where('payment_type', (new SmsTopUpPayment)->getMorphClass())
            ->whereHas('account', fn ($query) => $query->operational())
            ->where('status', FiscalReceiptStatus::Failed->value)
            ->count();
        $saasFiscalFailures = FiscalReceipt::query()
            ->where('scope_type', IntegrationScope::Platform->value)
            ->where('scope_id', 0)
            ->where('payment_type', (new AccountSubscriptionPayment)->getMorphClass())
            ->where(fn ($query) => $query
                ->whereNull('account_id')
                ->orWhereHas('account', fn ($query) => $query->operational()))
            ->where('status', FiscalReceiptStatus::Failed->value)
            ->count();

        $this->actingAs($admin)
            ->get(route('platform.sms-payments.index'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['fiscal_failed'] === $smsFiscalFailures);

        $this->actingAs($admin)
            ->get(route('platform.payments.index'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['fiscal_failed'] === $saasFiscalFailures);
    }

    private function enablePlatformFiscalization(): void
    {
        IntegrationSetting::factory()->create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'account_id' => null,
            'provider' => IntegrationProvider::LadnaFiscalization->value,
            'category' => IntegrationCategory::Fiscalization->value,
            'is_enabled' => true,
        ]);
        IntegrationSetting::factory()->create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'account_id' => null,
            'provider' => IntegrationProvider::Checkbox->value,
            'category' => IntegrationCategory::Fiscalization->value,
            'is_enabled' => true,
            'credentials' => [
                'license_key' => 'license-key',
                'cashier_login' => 'cashier-login',
                'cashier_password' => 'cashier-password',
            ],
        ]);
    }
}
