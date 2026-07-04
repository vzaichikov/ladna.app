<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudioCashflowCorrectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_studio_cashflow_permission_is_required_for_non_owner_payment_page_access(): void
    {
        $owner = User::factory()->create();
        $trainer = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $account->users()->syncWithoutDetaching([
            $trainer->id => ['role' => AccountRole::Trainer->value, 'permissions' => null],
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk();

        $this->actingAs($trainer)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertForbidden();

        $account->memberships()
            ->whereBelongsTo($trainer)
            ->update(['permissions' => [StudioPermission::ManageStudioCashflow->value]]);

        $this->actingAs($trainer->fresh())
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk()
            ->assertSee(__('app.cashdesk_balance'));
    }

    public function test_manual_cash_payment_edit_stores_history_and_updates_purchase(): void
    {
        [$owner, $account, $location, $secondLocation, $customerClassPass, $purchase] = $this->manualClassPassPaymentContext();

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.payments.index', $account))
            ->post(route('dashboard.accounts.payments.corrections.store', [$account, $purchase]), [
                'location_id' => $secondLocation->id,
                'amount' => '600.50',
                'paid_at' => '2026-07-04T12:30',
                'reason' => 'Trainer entered the wrong cash amount.',
            ])
            ->assertRedirect(route('dashboard.accounts.payments.index', $account));

        $purchase->refresh();
        $customerClassPass->refresh();

        $this->assertSame($secondLocation->id, $purchase->location_id);
        $this->assertSame(60050, $purchase->amount_cents);
        $this->assertTrue($purchase->paid_at->equalTo(Carbon::parse('2026-07-04 12:30:00', 'UTC')));
        $this->assertSame(60050, $customerClassPass->paid_amount_cents);
        $this->assertFalse($customerClassPass->is_paid);
        $this->assertDatabaseHas('customer_purchase_corrections', [
            'customer_purchase_id' => $purchase->id,
            'previous_location_id' => $location->id,
            'new_location_id' => $secondLocation->id,
            'previous_amount_cents' => 40000,
            'new_amount_cents' => 60050,
            'reason' => 'Trainer entered the wrong cash amount.',
        ]);
    }

    public function test_class_pass_manual_cash_payment_edit_recalculates_paid_status(): void
    {
        [$owner, $account, $location, , $customerClassPass, $purchase] = $this->manualClassPassPaymentContext();

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.payments.index', $account))
            ->post(route('dashboard.accounts.payments.corrections.store', [$account, $purchase]), [
                'location_id' => $location->id,
                'amount' => '1000.00',
                'paid_at' => '2026-07-04T13:00',
                'reason' => 'Full class pass amount was paid.',
            ])
            ->assertRedirect(route('dashboard.accounts.payments.index', $account));

        $customerClassPass->refresh();

        $this->assertSame(100000, $customerClassPass->paid_amount_cents);
        $this->assertTrue($customerClassPass->is_paid);
    }

    public function test_online_gateway_payment_edit_is_forbidden(): void
    {
        [$owner, $account, $location, , $customerClassPass, $purchase] = $this->manualClassPassPaymentContext();
        $purchase->update([
            'provider' => 'liqpay',
            'payment_source' => CustomerPurchase::SourceOnlineCheckout,
            'amount_cents' => 40000,
        ]);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.payments.index', $account))
            ->post(route('dashboard.accounts.payments.corrections.store', [$account, $purchase]), [
                'location_id' => $location->id,
                'amount' => '500.00',
                'paid_at' => '2026-07-04T14:00',
                'reason' => 'Should not edit gateway payment.',
            ])
            ->assertRedirect(route('dashboard.accounts.payments.index', $account))
            ->assertSessionHasErrors('reason');

        $this->assertSame(40000, $purchase->fresh()->amount_cents);
        $this->assertSame(0, $purchase->corrections()->count());
        $this->assertSame(40000, $customerClassPass->fresh()->paid_amount_cents);
    }

    public function test_cash_in_and_cash_out_entries_update_location_balance_and_are_tenant_scoped(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Main cashdesk']);
        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $otherLocation = Location::factory()->for($otherAccount)->create();

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.payments.index', $account))
            ->post(route('dashboard.accounts.cash-entries.store', $account), [
                'direction' => StudioCashEntry::DirectionIn,
                'location_id' => $location->id,
                'amount' => '100.00',
                'occurred_at' => '2026-07-04T10:00',
                'reason' => 'Owner added opening cash.',
            ])
            ->assertRedirect(route('dashboard.accounts.payments.index', $account));

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.payments.index', $account))
            ->post(route('dashboard.accounts.cash-entries.store', $account), [
                'direction' => StudioCashEntry::DirectionOut,
                'location_id' => $location->id,
                'amount' => '30.00',
                'occurred_at' => '2026-07-04T11:00',
                'reason' => 'Owner took cash from desk.',
            ])
            ->assertRedirect(route('dashboard.accounts.payments.index', $account));

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.payments.index', $account))
            ->post(route('dashboard.accounts.cash-entries.store', $account), [
                'direction' => StudioCashEntry::DirectionIn,
                'location_id' => $otherLocation->id,
                'amount' => '10.00',
                'occurred_at' => '2026-07-04T12:00',
                'reason' => 'Wrong tenant location.',
            ])
            ->assertRedirect(route('dashboard.accounts.payments.index', $account))
            ->assertSessionHasErrors('location_id');

        $this->assertSame(2, StudioCashEntry::whereBelongsTo($account)->count());
        $this->assertSame(0, StudioCashEntry::whereBelongsTo($otherAccount)->count());

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk()
            ->assertSee('Main cashdesk')
            ->assertSee(MoneyFormatter::format(7000, 'UAH'));
    }

    /**
     * @return array{0: User, 1: Account, 2: Location, 3: Location, 4: CustomerClassPass, 5: CustomerPurchase}
     */
    private function manualClassPassPaymentContext(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Main cashdesk', 'timezone' => 'UTC']);
        $secondLocation = Location::factory()->for($account)->create(['name' => 'Second cashdesk', 'timezone' => 'UTC']);
        $customer = Customer::factory()->for($account)->create(['name' => 'Cash Client']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Cash pass',
            'price_cents' => 100000,
            'currency' => 'UAH',
        ]);
        $customerClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'price_cents' => $plan->price_cents,
                'paid_amount_cents' => 40000,
                'is_paid' => false,
                'currency' => 'UAH',
            ]);
        $purchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->for($customerClassPass, 'customerClassPass')
            ->for($location)
            ->create([
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashClassPass,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'schedule_kind' => $plan->schedule_kind->value,
                'amount_cents' => 40000,
                'currency' => 'UAH',
                'paid_at' => Carbon::parse('2026-07-04 09:00:00', 'UTC'),
            ]);

        return [$owner, $account, $location, $secondLocation, $customerClassPass, $purchase];
    }
}
