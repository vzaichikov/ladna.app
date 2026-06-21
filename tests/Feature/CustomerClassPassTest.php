<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerClassPassTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_issue_manual_customer_class_pass(): void
    {
        [$owner, $account, $customer, $plan] = $this->passContext();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.class-passes.store', [$account, $customer]), [
                'class_pass_plan_id' => $plan->id,
            ])
            ->assertRedirect(route('dashboard.accounts.customers.edit', [$account, $customer]));

        $customerClassPass = $customer->customerClassPasses()->firstOrFail();

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $customerClassPass->code);
        $this->assertSame($plan->price_cents, $customerClassPass->price_cents);
        $this->assertSame($plan->sessions_count, $customerClassPass->sessions_count);
        $this->assertNull($customerClassPass->opened_at);
    }

    public function test_trial_class_pass_is_blocked_after_any_booking(): void
    {
        [$owner, $account, $customer, $plan, $scheduledClass] = $this->passContext(isTrial: true);

        ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.class-passes.store', [$account, $customer]), [
                'class_pass_plan_id' => $plan->id,
            ])
            ->assertSessionHasErrors('class_pass_plan_id');
    }

    public function test_customer_list_searches_by_class_pass_code(): void
    {
        [$owner, $account, $customer, $plan] = $this->passContext();
        app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);
        $code = $customer->customerClassPasses()->firstOrFail()->code;

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customers.index', ['account' => $account, 'q' => $code]))
            ->assertOk()
            ->assertSee($customer->name);
    }

    /**
     * @return array{0: User, 1: Account, 2: Customer, 3: ClassPassPlan, 4: ScheduledClass}
     */
    private function passContext(bool $isTrial = false): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        $trainer = Trainer::factory()->for($account)->for($trainerType)->create();
        $customer = Customer::factory()->for($account)->create(['name' => 'Олена Коваль']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'sessions_count' => 4,
            'is_trial' => $isTrial,
        ]);
        $plan->classTypes()->sync([$classType->id]);
        $plan->trainerTypes()->sync([$trainerType->id]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create();

        return [$owner, $account, $customer, $plan, $scheduledClass];
    }
}
