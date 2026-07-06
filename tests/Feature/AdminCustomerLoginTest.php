<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminCustomerLoginTest extends TestCase
{
    use DatabaseTransactions;

    public function test_studio_owner_can_login_to_customer_account_with_one_time_token(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Customer One',
            'phone' => '+380501112233',
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'Europe/Kyiv']);
        $room = Room::factory()->for($account)->for($location)->create();
        $direction = ActivityDirection::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->for($direction, 'activityDirection')->create();
        $trainer = Trainer::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create();
        ClassBooking::factory()
            ->for($account)
            ->for($customer)
            ->for($scheduledClass)
            ->for($owner, 'bookedBy')
            ->create();

        $response = $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.admin-login.store', [$account, $customer]));

        $response->assertRedirect();
        $consumeUrl = $response->headers->get('Location');

        $this->assertIsString($consumeUrl);

        $this->get($consumeUrl)
            ->assertRedirect(route('customer.dashboard', $account->slug));

        $this->assertAuthenticatedAs($customer, 'customer');

        $this->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee($scheduledClass->title);
    }

    public function test_admin_customer_login_token_can_only_be_used_once(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $customer = Customer::factory()->for($account)->create();

        $response = $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.admin-login.store', [$account, $customer]));

        $consumeUrl = $response->headers->get('Location');

        $this->assertIsString($consumeUrl);

        $this->get($consumeUrl)
            ->assertRedirect(route('customer.dashboard', $account->slug));

        auth()->guard('customer')->logout();

        $this->get($consumeUrl)
            ->assertNotFound();
    }

    public function test_non_owner_staff_cannot_issue_customer_admin_login_token(): void
    {
        $manager = User::factory()->create();
        $account = Account::factory()->create();
        $customer = Customer::factory()->for($account)->create();
        $account->users()->attach($manager->id, [
            'role' => AccountRole::Manager->value,
            'permissions' => [StudioPermission::ManageClients->value],
        ]);

        $this->actingAs($manager)
            ->post(route('dashboard.accounts.customers.admin-login.store', [$account, $customer]))
            ->assertForbidden();

        $this->assertGuest('customer');
    }

    public function test_platform_admin_cannot_issue_customer_admin_login_token(): void
    {
        $platformAdmin = User::factory()->create([
            'system_role' => SystemRole::PlatformAdmin,
        ]);
        $account = Account::factory()->create();
        $customer = Customer::factory()->for($account)->create();

        $this->actingAs($platformAdmin)
            ->post(route('dashboard.accounts.customers.admin-login.store', [$account, $customer]))
            ->assertForbidden();

        $this->assertGuest('customer');
    }

    public function test_customer_from_another_account_cannot_receive_admin_login_token(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $customer = Customer::factory()->for($otherAccount)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.admin-login.store', [$account, $customer]))
            ->assertNotFound();

        $this->assertGuest('customer');
    }
}
