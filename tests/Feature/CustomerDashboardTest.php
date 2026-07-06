<?php

namespace Tests\Feature;

use App\Enums\CustomerClassPassStatus;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerDashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dashboard_counts_and_lists_only_active_class_passes(): void
    {
        app()->setLocale('uk');

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-active-passes',
            'timezone' => 'UTC',
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Олена',
            'phone' => '+380501112233',
        ]);

        $this->classPass($account, $customer, [
            'code' => 'ACTIVE-001',
            'plan_name' => 'Active Pole',
            'sessions_count' => 10,
            'used_sessions_count' => 2,
            'reserved_sessions_count' => 3,
            'status' => CustomerClassPassStatus::Active->value,
            'is_active' => true,
        ]);
        $this->classPass($account, $customer, [
            'code' => 'CANCEL-001',
            'plan_name' => 'Cancelled Pole',
            'sessions_count' => 20,
            'used_sessions_count' => 1,
            'reserved_sessions_count' => 0,
            'status' => CustomerClassPassStatus::Cancelled->value,
            'is_active' => false,
        ]);
        $this->classPass($account, $customer, [
            'code' => 'FLAG-001',
            'plan_name' => 'Inactive Flag Pole',
            'sessions_count' => 7,
            'used_sessions_count' => 0,
            'reserved_sessions_count' => 0,
            'status' => CustomerClassPassStatus::Active->value,
            'is_active' => false,
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee('Залишок занять', false)
            ->assertSeeInOrder(['активних абонементів', '1', 'Залишок занять', '5', 'Записи', '0'], false)
            ->assertSee('ACTIVE-001', false)
            ->assertDontSee('CANCEL-001', false)
            ->assertDontSee('Cancelled Pole', false)
            ->assertDontSee('FLAG-001', false)
            ->assertDontSee('Inactive Flag Pole', false);
    }

    public function test_unopened_pass_uses_purchase_lifetime_as_use_by_date(): void
    {
        app()->setLocale('uk');

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-unopened-pass',
            'timezone' => 'UTC',
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Марія',
            'phone' => '+380501112234',
        ]);

        $this->classPass($account, $customer, [
            'code' => 'UNOPEN-001',
            'plan_name' => 'Unopened Pole',
            'purchased_at' => Carbon::parse('2026-06-30 10:00:00', 'UTC'),
            'opened_at' => null,
            'expires_at' => Carbon::parse('2026-07-31 23:59:00', 'UTC'),
            'usable_until_at' => Carbon::parse('2026-12-27 23:59:00', 'UTC'),
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSeeInOrder(['Unopened Pole', 'Куплено', '2026-06-30', 'Відкрито', '—', 'Використати до', '2026-12-27'], false)
            ->assertDontSee('Строк з першого заняття до', false)
            ->assertDontSee('2026-07-31', false);
    }

    public function test_opened_pass_uses_opening_expiry_as_use_by_date(): void
    {
        app()->setLocale('uk');

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-opened-pass',
            'timezone' => 'UTC',
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Ірина',
            'phone' => '+380501112235',
        ]);

        $this->classPass($account, $customer, [
            'code' => 'OPENED-001',
            'plan_name' => 'Opened Pole',
            'purchased_at' => Carbon::parse('2026-06-30 10:00:00', 'UTC'),
            'opened_at' => Carbon::parse('2026-07-01 09:00:00', 'UTC'),
            'expires_at' => Carbon::parse('2026-07-31 23:59:00', 'UTC'),
            'usable_until_at' => Carbon::parse('2026-12-27 23:59:00', 'UTC'),
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSeeInOrder(['Opened Pole', 'Куплено', '2026-06-30', 'Відкрито', '2026-07-01', 'Використати до', '2026-07-31'], false)
            ->assertDontSee('Строк з першого заняття до', false)
            ->assertDontSee('2026-12-27', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function classPass(Account $account, Customer $customer, array $attributes = []): CustomerClassPass
    {
        $classPassPlan = ClassPassPlan::factory()->for($account)->create();

        return CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($classPassPlan)
            ->create(array_merge([
                'status' => CustomerClassPassStatus::Active->value,
                'is_active' => true,
                'purchased_at' => Carbon::parse('2026-06-01 10:00:00', 'UTC'),
                'opened_at' => null,
                'expires_at' => null,
                'usable_until_at' => Carbon::parse('2026-12-01 10:00:00', 'UTC'),
            ], $attributes));
    }
}
