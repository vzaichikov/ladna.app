<?php

namespace Tests\Feature;

use App\Enums\CustomerClassPassStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Support\MoneyFormatter;
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
            ->assertSeeInOrder(['Записи', '0', 'Залишок занять', '5', 'активних абонементів', '1'], false)
            ->assertSeeInOrder(['Мої заняття', '0', 'Мої абонементи', '1'], false)
            ->assertDontSee('ACTIVE-001', false)
            ->assertDontSee('CANCEL-001', false)
            ->assertDontSee('Cancelled Pole', false)
            ->assertDontSee('FLAG-001', false)
            ->assertDontSee('Inactive Flag Pole', false);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', ['accountSlug' => $account->slug, 'tab' => 'passes']))
            ->assertOk()
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
            ->get(route('customer.dashboard', ['accountSlug' => $account->slug, 'tab' => 'passes']))
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
            ->get(route('customer.dashboard', ['accountSlug' => $account->slug, 'tab' => 'passes']))
            ->assertOk()
            ->assertSeeInOrder(['Opened Pole', 'Куплено', '2026-06-30', 'Відкрито', '2026-07-01', 'Використати до', '2026-07-31'], false)
            ->assertDontSee('Строк з першого заняття до', false)
            ->assertDontSee('2026-12-27', false);
    }

    public function test_default_classes_tab_highlights_booking_without_active_class_pass(): void
    {
        app()->setLocale('uk');
        Carbon::setTestNow(Carbon::parse('2026-07-06 08:00:00', 'UTC'));

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-bookings-tab',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Юлія',
            'phone' => '+380501112236',
        ]);
        $customerClassPass = $this->classPass($account, $customer, [
            'code' => 'PASS-001',
            'plan_name' => 'Covered Pole',
            'sessions_count' => 1,
            'used_sessions_count' => 0,
            'reserved_sessions_count' => 1,
        ]);

        $coveredClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Covered Exot',
                'starts_at' => Carbon::parse('2026-07-07 10:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-07-07 11:00:00', 'UTC'),
            ]);
        $coveredBooking = ClassBooking::factory()
            ->for($account)
            ->for($coveredClass, 'scheduledClass')
            ->for($customer)
            ->create();
        CustomerClassPassReservation::factory()->create([
            'account_id' => $account->id,
            'customer_class_pass_id' => $customerClassPass->id,
            'class_booking_id' => $coveredBooking->id,
            'scheduled_class_id' => $coveredClass->id,
            'status' => 'reserved',
            'reserved_at' => Carbon::parse('2026-07-06 08:10:00', 'UTC'),
        ]);

        $uncoveredClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'No Pass Tricks',
                'starts_at' => Carbon::parse('2026-07-07 11:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-07-07 12:00:00', 'UTC'),
            ]);
        ClassBooking::factory()
            ->for($account)
            ->for($uncoveredClass, 'scheduledClass')
            ->for($customer)
            ->create();

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSeeInOrder(['Мої заняття', '2', 'Мої абонементи', '1'], false)
            ->assertSee('No Pass Tricks', false)
            ->assertSee('На це заняття немає активного абонемента.', false)
            ->assertSee('Covered Exot', false)
            ->assertSee('PASS-001', false);

        Carbon::setTestNow();
    }

    public function test_default_classes_tab_shows_any_time_addon_instead_of_missing_pass_alert(): void
    {
        app()->setLocale('uk');
        Carbon::setTestNow(Carbon::parse('2026-07-06 08:00:00', 'UTC'));

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-any-time-addon',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Катерина',
            'phone' => '+380501112237',
        ]);
        $customerClassPass = $this->classPass($account, $customer, [
            'code' => 'MORN-001',
            'plan_name' => 'Morning Pole',
            'sessions_count' => 4,
            'used_sessions_count' => 0,
            'reserved_sessions_count' => 1,
            'available_from_time' => null,
            'available_until_time' => '12:00:00',
            'allows_any_time' => true,
            'any_time_addon_price_cents' => 4500,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Evening Exot',
                'starts_at' => Carbon::parse('2026-07-07 18:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-07-07 19:00:00', 'UTC'),
            ]);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($customer)
            ->create();
        CustomerClassPassReservation::factory()->create([
            'account_id' => $account->id,
            'customer_class_pass_id' => $customerClassPass->id,
            'class_booking_id' => $booking->id,
            'scheduled_class_id' => $scheduledClass->id,
            'status' => 'reserved',
            'reserved_at' => Carbon::parse('2026-07-06 08:10:00', 'UTC'),
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee('Evening Exot', false)
            ->assertSee('MORN-001', false)
            ->assertSee(__('app.customer_booking_any_time_addon_due', ['amount' => MoneyFormatter::format(4500, 'UAH')]), false)
            ->assertDontSee('На це заняття немає активного абонемента.', false);

        Carbon::setTestNow();
    }

    public function test_customer_dashboard_shows_public_links_for_active_studio_locations(): void
    {
        app()->setLocale('en');

        $account = Account::factory()->create([
            'default_language' => 'en',
            'slug' => 'customer-dashboard-public-links',
            'timezone' => 'UTC',
        ]);
        $activeLocation = Location::factory()->for($account)->create([
            'name' => 'Public Main',
            'slug' => 'public-main',
        ]);
        $inactiveLocation = Location::factory()->for($account)->create([
            'name' => 'Closed Main',
            'slug' => 'closed-main',
            'is_active' => false,
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Alice',
            'phone' => '+380501112238',
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'en'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee(__('app.public_links'))
            ->assertSee($activeLocation->name)
            ->assertSee(route('public.price', [$account->slug, $activeLocation->slug]), false)
            ->assertSee(route('public.schedule', [$account->slug, $activeLocation->slug]), false)
            ->assertDontSee($inactiveLocation->name)
            ->assertDontSee(route('public.price', [$account->slug, $inactiveLocation->slug]), false)
            ->assertDontSee(route('public.schedule', [$account->slug, $inactiveLocation->slug]), false);
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
