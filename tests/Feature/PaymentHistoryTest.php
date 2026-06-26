<?php

namespace Tests\Feature;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\FiscalReceipt;
use App\Models\IntegrationSetting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PaymentHistoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_studio_owner_can_view_customer_payment_history_with_fiscal_data_when_enabled(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio A']);
        $account->addOwner($owner);
        $this->enableAccountFiscalization($account);
        $purchase = $this->customerPurchase($account);
        FiscalReceipt::factory()
            ->forAccountScope($account)
            ->for($purchase, 'payment')
            ->fiscalized('FN-OWNER-1')
            ->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk()
            ->assertSee('Group 8 classes')
            ->assertSee('Payment Client')
            ->assertSee('FN-OWNER-1');
    }

    public function test_studio_owner_payment_history_hides_fiscal_data_when_ladna_fiscalization_is_disabled(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio B']);
        $account->addOwner($owner);
        $purchase = $this->customerPurchase($account);
        FiscalReceipt::factory()
            ->forAccountScope($account)
            ->for($purchase, 'payment')
            ->fiscalized('FN-HIDDEN-1')
            ->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk()
            ->assertSee('Group 8 classes')
            ->assertDontSee('FN-HIDDEN-1');
    }

    public function test_platform_admin_can_view_saas_payment_history_with_fiscal_data_when_enabled(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $this->enablePlatformFiscalization();
        $account = Account::factory()->create(['name' => 'Studio Platform']);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Studio Pro']);
        $payment = AccountSubscriptionPayment::factory()
            ->for($account)
            ->for($plan, 'plan')
            ->create([
                'payment_type' => AccountSubscriptionPaymentType::ManualRenewal->value,
                'status' => AccountSubscriptionPaymentStatus::PaymentPaid->value,
                'amount_cents' => 250000,
                'currency' => 'UAH',
                'paid_at' => now(),
            ]);
        FiscalReceipt::factory()
            ->forPlatformScope($account)
            ->for($payment, 'payment')
            ->fiscalized('FN-PLATFORM-1')
            ->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.payments.index'))
            ->assertOk()
            ->assertSee('Studio Pro')
            ->assertSee('Studio Platform')
            ->assertSee('FN-PLATFORM-1');
    }

    public function test_non_owner_cannot_view_studio_payment_history(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertForbidden();
    }

    private function customerPurchase(Account $account): CustomerPurchase
    {
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Payment Client',
            'phone' => '+380501119900',
        ]);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Group 8 classes',
            'price_cents' => 180000,
            'currency' => 'UAH',
            'sessions_count' => 8,
        ]);

        return CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'sessions_count' => $plan->sessions_count,
                'paid_at' => now(),
            ]);
    }

    private function enableAccountFiscalization(Account $account): void
    {
        $this->enableFiscalizationSetting(IntegrationScope::Account, IntegrationProvider::LadnaFiscalization, $account);
        $this->enableFiscalizationSetting(IntegrationScope::Account, IntegrationProvider::Checkbox, $account, $this->checkboxCredentials());
    }

    private function enablePlatformFiscalization(): void
    {
        $this->enableFiscalizationSetting(IntegrationScope::Platform, IntegrationProvider::LadnaFiscalization);
        $this->enableFiscalizationSetting(IntegrationScope::Platform, IntegrationProvider::Checkbox, credentials: $this->checkboxCredentials());
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function enableFiscalizationSetting(
        IntegrationScope $scope,
        IntegrationProvider $provider,
        ?Account $account = null,
        array $credentials = [],
    ): void {
        IntegrationSetting::updateOrCreate(
            [
                'scope_type' => $scope->value,
                'scope_id' => $scope === IntegrationScope::Account ? $account?->id : 0,
                'provider' => $provider->value,
            ],
            [
                'account_id' => $account?->id,
                'category' => IntegrationCategory::Fiscalization->value,
                'is_enabled' => true,
                'credentials' => $credentials,
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function checkboxCredentials(): array
    {
        return [
            'license_key' => 'license-key',
            'cashier_pin_code' => '1234',
            'client_name' => 'Ladna',
            'client_version' => 'test',
        ];
    }
}
