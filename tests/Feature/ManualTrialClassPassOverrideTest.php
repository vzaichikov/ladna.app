<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\ReconcileUnreservedCustomerBookingsForIssuedClassPass;
use App\Enums\AccountRole;
use App\Enums\CustomerClassPassAdjustmentType;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassAdjustment;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class ManualTrialClassPassOverrideTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ineligible_trial_succeeds_only_with_a_valid_audited_override_and_uses_the_oldest_suitable_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 10:00:00', 'Europe/Kyiv'));
        $context = $this->context();
        $customer = $context['customer'];
        $bookings = [
            $this->booking($context, $customer, '2026-07-23 18:00:00', 'cancelled'),
            $this->booking($context, $customer, '2026-07-23 19:00:00', 'attended'),
            $this->booking($context, $customer, '2026-07-30 19:00:00', 'booked'),
            $this->booking($context, $customer, '2026-07-27 20:00:00', 'cancelled'),
            $this->booking($context, $customer, '2026-07-28 19:00:00', 'attended'),
        ];

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.customers.edit', [$context['account'], $customer]))
            ->assertOk()
            ->assertSee(__('app.trial_class_pass_override_title'))
            ->assertSee('name="override_trial_eligibility"', false)
            ->assertSee('name="trial_eligibility_override_reason"', false)
            ->assertSee('data-trial-plan-select', false);

        $this->actingAs($context['owner'])
            ->post($this->storeRoute($context), $this->issuePayload($context))
            ->assertSessionHasErrors('class_pass_plan_id');

        $this->assertSame(0, $customer->customerClassPasses()->count());
        $this->assertSame(0, CustomerClassPassAdjustment::whereBelongsTo($context['account'])->count());

        $comment = 'Verified exceptional first-visit offer after staff review.';

        $this->actingAs($context['owner'])
            ->post($this->storeRoute($context), [
                ...$this->issuePayload($context),
                'override_trial_eligibility' => '1',
                'trial_eligibility_override_reason' => $comment,
            ])
            ->assertRedirect(route('dashboard.accounts.customers.edit', [$context['account'], $customer]));

        $classPass = $customer->customerClassPasses()->sole();
        $adjustment = $classPass->adjustments()->sole();

        $this->assertSame(CustomerClassPassAdjustmentType::TrialEligibilityOverride, $adjustment->adjustment_type);
        $this->assertSame($comment, $adjustment->reason);
        $this->assertSame($context['owner']->id, $adjustment->actor_user_id);
        $this->assertSame($context['owner']->name, $adjustment->actor_name);
        $this->assertSame('owner', $adjustment->actor_role);
        $this->assertSame(
            $classPass->id,
            $bookings[1]->classPassReservation()->firstOrFail()->customer_class_pass_id,
        );
        $this->assertSame(
            CustomerClassPassReservationStatus::Used,
            $bookings[1]->classPassReservation()->firstOrFail()->status,
        );
        $this->assertFalse($bookings[0]->classPassReservation()->exists());
        $this->assertFalse($bookings[2]->classPassReservation()->exists());
        $this->assertFalse($bookings[3]->classPassReservation()->exists());
        $this->assertFalse($bookings[4]->classPassReservation()->exists());

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.customer-class-passes.edit', [$context['account'], $classPass]))
            ->assertOk()
            ->assertSee(__('app.adjustment_trial_eligibility_override'))
            ->assertSee(__('app.trial_class_pass_override_history_label'))
            ->assertSee($comment)
            ->assertSee($context['owner']->name);

        Carbon::setTestNow();
    }

    public function test_override_requires_checkbox_and_a_trimmed_comment_between_three_and_two_thousand_characters(): void
    {
        $context = $this->context();
        $this->makeIneligible($context, $context['customer']);

        foreach ([
            ['trial_eligibility_override_reason' => 'Comment without checkbox'],
            ['override_trial_eligibility' => '1'],
            ['override_trial_eligibility' => '1', 'trial_eligibility_override_reason' => '  '],
            ['override_trial_eligibility' => '1', 'trial_eligibility_override_reason' => str_repeat('x', 2001)],
        ] as $overridePayload) {
            $this->actingAs($context['owner'])
                ->post($this->storeRoute($context), [
                    ...$this->issuePayload($context),
                    ...$overridePayload,
                ])
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, $context['customer']->customerClassPasses()->count());
        $this->assertSame(0, CustomerClassPassAdjustment::whereBelongsTo($context['account'])->count());
    }

    public function test_override_requires_both_issue_and_manage_permissions(): void
    {
        $context = $this->context();
        $this->makeIneligible($context, $context['customer']);
        $issueOnlyUser = User::factory()->create();
        $context['account']->users()->attach($issueOnlyUser->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::IssueCustomerClassPasses->value],
        ]);

        $this->actingAs($issueOnlyUser)
            ->post($this->storeRoute($context), [
                ...$this->issuePayload($context),
                'override_trial_eligibility' => '1',
                'trial_eligibility_override_reason' => 'Issue-only permission must not be enough.',
            ])
            ->assertSessionHasErrors('override_trial_eligibility');

        $this->assertSame(0, $context['customer']->customerClassPasses()->count());
        $this->assertSame(0, CustomerClassPassAdjustment::whereBelongsTo($context['account'])->count());
    }

    public function test_any_previous_pass_status_or_unpaid_pass_blocks_the_override(): void
    {
        $context = $this->context();
        $cases = [
            ['status' => CustomerClassPassStatus::Active, 'is_active' => true, 'is_paid' => true],
            ['status' => CustomerClassPassStatus::Expired, 'is_active' => false, 'is_paid' => true],
            ['status' => CustomerClassPassStatus::Cancelled, 'is_active' => false, 'is_paid' => true],
            ['status' => CustomerClassPassStatus::UsedUp, 'is_active' => false, 'is_paid' => true],
            ['status' => CustomerClassPassStatus::Active, 'is_active' => true, 'is_paid' => false],
        ];

        foreach ($cases as $index => $case) {
            $customer = Customer::factory()
                ->for($context['account'])
                ->create(['name' => 'Pass history customer '.$index]);
            $this->makeIneligible($context, $customer);
            CustomerClassPass::factory()
                ->for($context['account'])
                ->for($customer)
                ->for($context['regular_plan'], 'classPassPlan')
                ->create([
                    'status' => $case['status']->value,
                    'is_active' => $case['is_active'],
                    'is_paid' => $case['is_paid'],
                    'closed_at' => $case['is_active'] ? null : now(),
                ]);

            $this->actingAs($context['owner'])
                ->post(
                    route('dashboard.accounts.customers.class-passes.store', [$context['account'], $customer]),
                    [
                        ...$this->issuePayload($context),
                        'override_trial_eligibility' => '1',
                        'trial_eligibility_override_reason' => 'Previous pass history must block this exception.',
                    ],
                )
                ->assertSessionHasErrors('override_trial_eligibility');

            $this->assertSame(1, $customer->customerClassPasses()->count());
            $this->assertSame(0, $customer->customerClassPasses()->firstOrFail()->adjustments()->count());
        }
    }

    public function test_any_successful_booking_or_class_pass_payment_blocks_the_override(): void
    {
        $context = $this->context();

        foreach ([
            CustomerPurchase::SourceManualCashBooking,
            CustomerPurchase::SourceManualCashClassPass,
            CustomerPurchase::SourceOnlineCheckout,
        ] as $index => $paymentSource) {
            $customer = Customer::factory()
                ->for($context['account'])
                ->create(['name' => 'Paid customer '.$index]);
            $this->makeIneligible($context, $customer);
            CustomerPurchase::factory()
                ->for($context['account'])
                ->for($customer)
                ->for($context['regular_plan'], 'classPassPlan')
                ->create([
                    'payment_source' => $paymentSource,
                    'status' => 'payment_paid',
                    'paid_at' => now(),
                ]);

            $this->actingAs($context['owner'])
                ->post(
                    route('dashboard.accounts.customers.class-passes.store', [$context['account'], $customer]),
                    [
                        ...$this->issuePayload($context),
                        'override_trial_eligibility' => '1',
                        'trial_eligibility_override_reason' => 'Successful payment history must block this exception.',
                    ],
                )
                ->assertSessionHasErrors('override_trial_eligibility');

            $this->assertSame(0, $customer->customerClassPasses()->count());
        }
    }

    public function test_normally_eligible_trial_and_regular_pass_issuance_create_no_override_audit(): void
    {
        $context = $this->context();

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.customers.edit', [$context['account'], $context['customer']]))
            ->assertOk()
            ->assertDontSee(__('app.trial_class_pass_override_title'))
            ->assertDontSee('name="override_trial_eligibility"', false);

        $this->actingAs($context['owner'])
            ->post($this->storeRoute($context), $this->issuePayload($context))
            ->assertRedirect();

        $regularCustomer = Customer::factory()->for($context['account'])->create();
        $this->actingAs($context['owner'])
            ->post(
                route('dashboard.accounts.customers.class-passes.store', [$context['account'], $regularCustomer]),
                [
                    'class_pass_plan_id' => $context['regular_plan']->id,
                    'issued_location_id' => $context['location']->id,
                ],
            )
            ->assertRedirect();

        $this->assertSame(2, CustomerClassPass::whereBelongsTo($context['account'])->count());
        $this->assertSame(0, CustomerClassPassAdjustment::whereBelongsTo($context['account'])->count());
    }

    public function test_forged_override_fields_are_rejected_for_normally_eligible_trial_non_trial_and_online_issuance(): void
    {
        $context = $this->context();
        $payload = [
            'override_trial_eligibility' => '1',
            'trial_eligibility_override_reason' => 'Forged exception.',
        ];

        $this->actingAs($context['owner'])
            ->post($this->storeRoute($context), [
                ...$this->issuePayload($context),
                ...$payload,
            ])
            ->assertSessionHasErrors('override_trial_eligibility');

        $this->makeIneligible($context, $context['customer']);

        $this->actingAs($context['owner'])
            ->post($this->storeRoute($context), [
                'class_pass_plan_id' => $context['regular_plan']->id,
                'issued_location_id' => $context['location']->id,
                ...$payload,
            ])
            ->assertSessionHasErrors('override_trial_eligibility');

        try {
            app(IssueCustomerClassPass::class)->execute(
                $context['account'],
                $context['customer'],
                $context['trial_plan'],
                source: 'online_payment',
                trialEligibilityOverrideReason: 'Online exception attempt.',
            );
            $this->fail('Online trial override should have been rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, $context['customer']->customerClassPasses()->count());
        $this->assertSame(0, CustomerClassPassAdjustment::whereBelongsTo($context['account'])->count());
    }

    public function test_override_pass_and_audit_are_rolled_back_together_when_reconciliation_fails(): void
    {
        $context = $this->context();
        $this->makeIneligible($context, $context['customer']);
        $this->mock(ReconcileUnreservedCustomerBookingsForIssuedClassPass::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->andThrow(new \RuntimeException('Synthetic reconciliation failure.'));
        });

        $this->actingAs($context['owner'])
            ->post($this->storeRoute($context), [
                ...$this->issuePayload($context),
                'override_trial_eligibility' => '1',
                'trial_eligibility_override_reason' => 'Rollback probe.',
            ])
            ->assertServerError();

        $this->assertSame(0, $context['customer']->customerClassPasses()->count());
        $this->assertSame(0, CustomerClassPassAdjustment::whereBelongsTo($context['account'])->count());
    }

    /**
     * @return array{
     *     owner: User,
     *     account: Account,
     *     customer: Customer,
     *     location: Location,
     *     room: Room,
     *     class_type: ClassType,
     *     trainer: Trainer,
     *     trial_plan: ClassPassPlan,
     *     regular_plan: ClassPassPlan
     * }
     */
    private function context(): array
    {
        $owner = User::factory()->create(['name' => 'Override Owner']);
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        $trainer = Trainer::factory()->for($account)->for($trainerType)->create();
        $customer = Customer::factory()->for($account)->create(['name' => 'Anna Override']);
        $trialPlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Trial 250',
            'is_trial' => true,
            'price_cents' => 25000,
            'sessions_count' => 1,
            'validity_days' => 30,
            'total_validity_days' => 180,
        ]);
        $regularPlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Regular 450',
            'is_trial' => false,
            'price_cents' => 45000,
            'sessions_count' => 1,
        ]);

        foreach ([$trialPlan, $regularPlan] as $plan) {
            $plan->classTypes()->sync([$classType->id]);
            $plan->trainerTypes()->sync([$trainerType->id]);
        }

        return [
            'owner' => $owner,
            'account' => $account,
            'customer' => $customer,
            'location' => $location,
            'room' => $room,
            'class_type' => $classType,
            'trainer' => $trainer,
            'trial_plan' => $trialPlan,
            'regular_plan' => $regularPlan,
        ];
    }

    private function booking(
        array $context,
        Customer $customer,
        string $startsAt,
        string $status = 'booked',
    ): ClassBooking {
        $startsAt = Carbon::parse($startsAt, 'Europe/Kyiv')->utc();
        $scheduledClass = ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($context['room'])
            ->for($context['class_type'])
            ->for($context['trainer'])
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);

        return ClassBooking::factory()
            ->for($context['account'])
            ->for($scheduledClass)
            ->for($customer)
            ->create([
                'status' => $status,
                'attended_at' => $status === 'attended' ? $startsAt->copy()->addHours(2) : null,
            ]);
    }

    private function makeIneligible(array $context, Customer $customer): void
    {
        $this->booking($context, $customer, '2026-07-23 19:00:00');
        $this->booking($context, $customer, '2026-07-28 19:00:00');
    }

    private function storeRoute(array $context): string
    {
        return route('dashboard.accounts.customers.class-passes.store', [
            $context['account'],
            $context['customer'],
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function issuePayload(array $context): array
    {
        return [
            'class_pass_plan_id' => $context['trial_plan']->id,
            'issued_location_id' => $context['location']->id,
        ];
    }
}
