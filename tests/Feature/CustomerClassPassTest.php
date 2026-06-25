<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Enums\AccountRole;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
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
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerClassPassTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_issue_manual_customer_class_pass(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
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
        $this->assertSame($plan->validity_days, $customerClassPass->validity_days);
        $this->assertSame($plan->total_validity_days, $customerClassPass->total_validity_days);
        $this->assertTrue($customerClassPass->purchased_at->equalTo(Carbon::parse('2026-06-20 10:00:00')));
        $this->assertTrue($customerClassPass->usable_until_at->equalTo(Carbon::parse('2026-10-18 10:00:00')));
        $this->assertNull($customerClassPass->opened_at);

        Carbon::setTestNow();
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

    public function test_owner_can_add_sessions_to_customer_class_pass_and_history_is_stored(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [$owner, $account, $customer, $plan] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'sessions_delta' => 2,
                'reason' => 'Medical recovery compensation',
            ])
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]))
            ->assertSessionHas('status', __('app.customer_class_pass_adjusted'));

        $customerClassPass->refresh();
        $adjustment = $customerClassPass->adjustments()->firstOrFail();

        $this->assertSame(6, $customerClassPass->sessions_count);
        $this->assertSame(2, $adjustment->sessions_delta);
        $this->assertSame(4, $adjustment->previous_sessions_count);
        $this->assertSame(6, $adjustment->new_sessions_count);
        $this->assertSame('Medical recovery compensation', $adjustment->reason);
        $this->assertSame($owner->id, $adjustment->user_id);
        $this->assertSame($account->id, $adjustment->account_id);

        Carbon::setTestNow();
    }

    public function test_non_owner_cannot_add_sessions_to_customer_class_pass(): void
    {
        [, $account, $customer, $plan] = $this->passContext();
        $manager = User::factory()->create();
        $account->users()->attach($manager->id, [
            'role' => AccountRole::Admin->value,
        ]);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $this->actingAs($manager)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'sessions_delta' => 2,
                'reason' => 'Manager attempt',
            ])
            ->assertForbidden();

        $customerClassPass->refresh();
        $this->assertSame(4, $customerClassPass->sessions_count);
        $this->assertSame(0, $customerClassPass->adjustments()->count());
    }

    public function test_owner_can_reopen_valid_used_up_pass_with_session_adjustment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [$owner, $account, $customer, $plan, $scheduledClass] = $this->passContext();
        $plan->update(['sessions_count' => 1]);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create(['status' => 'attended', 'attended_at' => Carbon::parse('2026-06-19 10:00:00')]);

        $customerClassPass->reservations()->create([
            'account_id' => $account->id,
            'class_booking_id' => $booking->id,
            'scheduled_class_id' => $scheduledClass->id,
            'status' => CustomerClassPassReservationStatus::Used->value,
            'reserved_at' => Carbon::parse('2026-06-18 10:00:00'),
            'used_at' => Carbon::parse('2026-06-19 10:00:00'),
        ]);
        $customerClassPass->forceFill([
            'status' => CustomerClassPassStatus::UsedUp->value,
            'is_active' => false,
            'used_sessions_count' => 1,
            'closed_at' => Carbon::parse('2026-06-19 10:00:00'),
        ])->save();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'sessions_delta' => 1,
                'reason' => 'Force majeure replacement',
            ])
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]));

        $customerClassPass->refresh();
        $this->assertSame(2, $customerClassPass->sessions_count);
        $this->assertSame(CustomerClassPassStatus::Active, $customerClassPass->status);
        $this->assertTrue($customerClassPass->is_active);
        $this->assertNull($customerClassPass->closed_at);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertSame(1, $customerClassPass->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_session_adjustments_reject_cancelled_expired_and_cross_account_passes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [$owner, $account, $customer, $plan] = $this->passContext();
        $cancelledPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);
        $expiredPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan, purchasedAt: Carbon::parse('2026-01-01 10:00:00'));

        $cancelledPass->forceFill([
            'status' => CustomerClassPassStatus::Cancelled->value,
            'is_active' => false,
            'closed_at' => now(),
        ])->save();
        $expiredPass->forceFill([
            'status' => CustomerClassPassStatus::Expired->value,
            'is_active' => false,
            'usable_until_at' => Carbon::parse('2026-01-10 10:00:00'),
            'closed_at' => Carbon::parse('2026-01-10 10:00:00'),
        ])->save();

        $payload = ['sessions_delta' => 1, 'reason' => 'Invalid compensation'];

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $cancelledPass]), $payload)
            ->assertSessionHasErrors('sessions_delta');
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $expiredPass]), $payload)
            ->assertSessionHasErrors('sessions_delta');

        $otherAccount = Account::factory()->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create();
        $otherPlan = ClassPassPlan::factory()->for($otherAccount)->create();
        $otherPass = app(IssueCustomerClassPass::class)->execute($otherAccount, $otherCustomer, $otherPlan);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $otherPass]), $payload)
            ->assertNotFound();

        $this->assertSame(0, $cancelledPass->adjustments()->count());
        $this->assertSame(0, $expiredPass->adjustments()->count());
        $this->assertSame(0, $otherPass->adjustments()->count());

        Carbon::setTestNow();
    }

    public function test_purchased_pass_keeps_session_snapshot_when_plan_changes(): void
    {
        [$owner, $account, $customer, $plan] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $plan->update([
            'sessions_count' => 35,
            'validity_days' => 45,
            'total_validity_days' => 365,
        ]);

        $customerClassPass->refresh();
        $this->assertSame(4, $customerClassPass->sessions_count);
        $this->assertSame(30, $customerClassPass->validity_days);
        $this->assertSame(120, $customerClassPass->total_validity_days);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]))
            ->assertOk()
            ->assertSee((string) $customerClassPass->sessions_count);
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
            'validity_days' => 30,
            'total_validity_days' => 120,
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
