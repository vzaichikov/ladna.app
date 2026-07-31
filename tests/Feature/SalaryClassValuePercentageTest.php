<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\SalaryClassFormulaType;
use App\Enums\SalaryModelType;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\Location;
use App\Models\Room;
use App\Models\SalaryModel;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Salary\TrainerSalaryCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalaryClassValuePercentageTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_percentage_formula_is_validated_persisted_and_available_in_schedule_kind_tabs(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $groupClassType = $this->classType($account, 'Pole group', ScheduleKind::GroupClass);
        $privateClassType = $this->classType($account, 'Private pole', ScheduleKind::PrivateLesson);
        $rentalClassType = $this->classType($account, 'Main hall rent', ScheduleKind::RoomRental);

        $form = $this->actingAs($owner)->get(route('dashboard.accounts.salary-models.create', $account));

        $form->assertOk()
            ->assertSee(__('app.salary_formula_class_value_percentage'))
            ->assertSee('role="tablist"', false)
            ->assertSeeInOrder([
                'data-salary-rule-panel="group_class"',
                $groupClassType->name,
                'data-salary-rule-panel="private_lesson"',
                $privateClassType->name,
                'data-salary-rule-panel="room_rental"',
                $rentalClassType->name,
            ], false);

        foreach (['0', '0.001', '100.01'] as $invalidPercentage) {
            $this->from(route('dashboard.accounts.salary-models.create', $account))
                ->post(route('dashboard.accounts.salary-models.store', $account), $this->salaryModelPayload(
                    name: 'Invalid '.$invalidPercentage,
                    percentage: $invalidPercentage,
                ))
                ->assertRedirect(route('dashboard.accounts.salary-models.create', $account))
                ->assertSessionHasErrors('rules.0.class_value_percentage');
        }

        foreach (['0.01' => 1, '100.00' => 10000] as $percentage => $basisPoints) {
            $this->post(
                route('dashboard.accounts.salary-models.store', $account),
                $this->salaryModelPayload('Boundary '.$percentage, $percentage),
            )->assertSessionHasNoErrors();

            $this->assertDatabaseHas('salary_model_class_rules', [
                'account_id' => $account->id,
                'formula_type' => SalaryClassFormulaType::ClassValuePercentage->value,
                'class_value_percentage_basis_points' => $basisPoints,
            ]);
        }

        $this->from(route('dashboard.accounts.salary-models.create', $account))
            ->post(route('dashboard.accounts.salary-models.store', $account), [
                ...$this->salaryModelPayload('Invalid private override', '50.00'),
                'salary_schedule_kind_tab' => ScheduleKind::RoomRental->value,
                'rules' => [
                    $this->percentageRule('0'),
                    $this->percentageRule('0', $privateClassType),
                ],
            ])
            ->assertSessionHasErrors('rules.1.class_value_percentage');

        $this->assertSame(
            ['rules.0.class_value_percentage', 'rules.1.class_value_percentage'],
            session('errors')->getBag('default')->keys(),
        );
        $this->assertSame($privateClassType->id, (int) session('_old_input.rules.1.class_type_id'));
        $this->assertSame(
            ScheduleKind::PrivateLesson->value,
            session('salary_model_error_schedule_kind_tab'),
        );

        $rerenderedForm = $this->get(route('dashboard.accounts.salary-models.create', $account))->assertOk();
        preg_match('/data-active-tab="([^"]+)"/', $rerenderedForm->getContent(), $activeTabMatches);

        $this->assertSame(ScheduleKind::PrivateLesson->value, $activeTabMatches[1] ?? null);

        $this->from(route('dashboard.accounts.salary-models.create', $account))
            ->post(route('dashboard.accounts.salary-models.store', $account), [
                ...$this->salaryModelPayload('', '50.00'),
                'salary_schedule_kind_tab' => ScheduleKind::RoomRental->value,
            ])
            ->assertSessionHasErrors('name');

        $rerenderedForm = $this->get(route('dashboard.accounts.salary-models.create', $account))->assertOk();
        preg_match('/data-active-tab="([^"]+)"/', $rerenderedForm->getContent(), $activeTabMatches);

        $this->assertSame(ScheduleKind::RoomRental->value, $activeTabMatches[1] ?? null);

        $emptyAccount = Account::factory()->create();
        $emptyAccount->addOwner($owner);
        $emptyForm = $this->get(route('dashboard.accounts.salary-models.create', $emptyAccount))->assertOk();

        $this->assertSame(
            3,
            substr_count($emptyForm->getContent(), __('app.salary_no_class_types_for_kind')),
        );
    }

    public function test_group_class_value_aggregates_pass_shares_addons_and_refunds_before_rounding_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));
        [$owner, $account, $trainer, $location, $classType] = $this->salaryContext(ScheduleKind::GroupClass);
        $model = $this->percentageModel($account, 5000);
        $this->assign($account, $trainer, $model);
        $scheduledClass = $this->scheduledClass($account, $trainer, $location, $classType, '2026-06-10 10:00:00');
        $firstBooking = $this->booking($account, $scheduledClass, 'Pass and add-on');
        $this->passReservation($account, $scheduledClass, $firstBooking, 1001, 3);
        $addOn = $this->directPayment($account, $firstBooking, 4500);
        $this->refund($account, $addOn, 500);
        $secondBooking = $this->booking($account, $scheduledClass, 'Different pass');
        $this->passReservation($account, $scheduledClass, $secondBooking, 15000, 3);

        $result = $this->calculate($account, $trainer);
        $entry = $result['entries']->sole();

        $this->assertSame(4667, $result['amounts']['UAH']);
        $this->assertSame(9334, $entry['class_value_cents']);
        $this->assertSame(5334, $entry['class_value_pass_cents']);
        $this->assertSame(4000, $entry['class_value_direct_cents']);
        $this->assertSame(2, $entry['class_value_bookings_count']);
        $this->assertSame('93.34 × 50% = 46.67', $entry['formula']);
        $this->assertFalse($result['incomplete']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers.salary', [
                'account' => $account,
                'trainer' => $trainer,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk()
            ->assertSee('93.34 × 50% = 46.67 UAH')
            ->assertSee(__('app.salary_class_value_from_passes'))
            ->assertSee(__('app.salary_class_value_direct'));
    }

    public function test_private_and_rental_class_values_are_counted_once_regardless_of_people_capacity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));

        foreach ([
            ScheduleKind::PrivateLesson->value => ['value' => 50000, 'expected' => 25000, 'capacity' => 4],
            ScheduleKind::RoomRental->value => ['value' => 40000, 'expected' => 20000, 'capacity' => 7],
        ] as $scheduleKindValue => $case) {
            $scheduleKind = ScheduleKind::from($scheduleKindValue);
            [, $account, $trainer, $location, $classType] = $this->salaryContext($scheduleKind);
            $model = $this->percentageModel($account, 5000);
            $this->assign($account, $trainer, $model);
            $scheduledClass = $this->scheduledClass(
                $account,
                $trainer,
                $location,
                $classType,
                '2026-06-10 10:00:00',
                $case['capacity'],
            );
            $booking = $this->booking($account, $scheduledClass, $scheduleKind->value);

            if ($scheduleKind === ScheduleKind::PrivateLesson) {
                $this->passReservation($account, $scheduledClass, $booking, $case['value'], 1);
            } else {
                $payment = $this->directPayment($account, $booking, 50000);
                $this->refund($account, $payment, 10000);
            }

            $result = $this->calculate($account, $trainer);
            $entry = $result['entries']->sole();

            $this->assertSame($case['expected'], $result['amounts']['UAH'], $scheduleKind->value);
            $this->assertSame($case['value'], $entry['class_value_cents'], $scheduleKind->value);
            $this->assertSame($case['capacity'], $entry['counted_people'], $scheduleKind->value);
            $this->assertSame(1, $entry['class_value_bookings_count'], $scheduleKind->value);
        }
    }

    public function test_percentage_uses_aggregate_half_up_rounding_limits_and_class_type_override(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));
        [, $account, $trainer, $location, $defaultClassType] = $this->salaryContext(ScheduleKind::GroupClass);
        $overrideClassType = $this->classType($account, 'Override class', ScheduleKind::GroupClass);
        $model = $this->percentageModel(
            $account,
            5000,
            overrideClassType: $overrideClassType,
            overrideBasisPoints: 2500,
        );
        $this->assign($account, $trainer, $model);

        $roundingClass = $this->scheduledClass($account, $trainer, $location, $defaultClassType, '2026-06-10 10:00:00');
        foreach (['First cent', 'Second cent'] as $customerName) {
            $booking = $this->booking($account, $roundingClass, $customerName);
            $this->passReservation($account, $roundingClass, $booking, 1, 1);
        }

        $overrideClass = $this->scheduledClass($account, $trainer, $location, $overrideClassType, '2026-06-11 10:00:00');
        $overrideBooking = $this->booking($account, $overrideClass, 'Override customer');
        $this->directPayment($account, $overrideBooking, 10000);

        $result = $this->calculate($account, $trainer);
        $entries = $result['entries']->keyBy('class_type');

        $this->assertSame(2501, $result['amounts']['UAH']);
        $this->assertSame(1, $entries->get($defaultClassType->name)['amount_cents']);
        $this->assertSame('0.02 × 50% = 0.01', $entries->get($defaultClassType->name)['formula']);
        $this->assertSame(2500, $entries->get($overrideClassType->name)['amount_cents']);
        $this->assertSame('100.00 × 25% = 25.00', $entries->get($overrideClassType->name)['formula']);

        [, $limitedAccount, $limitedTrainer, $limitedLocation, $limitedClassType] = $this->salaryContext(ScheduleKind::GroupClass);
        $minimumModel = $this->percentageModel($limitedAccount, 5000, minimumPayCents: 100);
        $this->assign($limitedAccount, $limitedTrainer, $minimumModel);
        $minimumClass = $this->scheduledClass(
            $limitedAccount,
            $limitedTrainer,
            $limitedLocation,
            $limitedClassType,
            '2026-06-10 10:00:00',
        );
        $minimumBooking = $this->booking($limitedAccount, $minimumClass, 'Minimum');
        $this->directPayment($limitedAccount, $minimumBooking, 1);

        $minimumEntry = $this->calculate($limitedAccount, $limitedTrainer)['entries']->sole();
        $this->assertSame(100, $minimumEntry['amount_cents']);
        $this->assertSame('0.01 × 50% = 0.01 → 1.00', $minimumEntry['formula']);

        $maximumModel = $this->percentageModel($limitedAccount, 10000, maximumPayCents: 200);
        $secondTrainer = Trainer::factory()->for($limitedAccount)->create();
        $this->assign($limitedAccount, $secondTrainer, $maximumModel);
        $maximumClass = $this->scheduledClass(
            $limitedAccount,
            $secondTrainer,
            $limitedLocation,
            $limitedClassType,
            '2026-06-11 10:00:00',
        );
        $maximumBooking = $this->booking($limitedAccount, $maximumClass, 'Maximum');
        $this->directPayment($limitedAccount, $maximumBooking, 500);

        $maximumEntry = $this->calculate($limitedAccount, $secondTrainer)['entries']->sole();
        $this->assertSame(200, $maximumEntry['amount_cents']);
        $this->assertSame('5.00 × 100% = 5.00 → 2.00', $maximumEntry['formula']);
    }

    public function test_free_empty_missing_currency_and_tenant_mismatch_values_are_explained(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));

        $cases = [
            'free pass' => ['expected' => 0, 'reason' => null],
            'missing coverage' => ['expected' => null, 'reason' => 'salary_reason_class_value_missing'],
            'invalid pass sessions' => ['expected' => null, 'reason' => 'salary_reason_class_value_missing'],
            'unpaid direct payment' => ['expected' => null, 'reason' => 'salary_reason_class_value_missing'],
            'currency mismatch' => ['expected' => null, 'reason' => 'salary_reason_class_value_currency_mismatch'],
            'refund currency mismatch' => ['expected' => null, 'reason' => 'salary_reason_class_value_currency_mismatch'],
            'tenant mismatch' => ['expected' => null, 'reason' => 'salary_reason_class_value_missing'],
        ];

        foreach ($cases as $name => $case) {
            [$owner, $account, $trainer, $location, $classType] = $this->salaryContext(ScheduleKind::GroupClass);
            $model = $this->percentageModel($account, 5000);
            $this->assign($account, $trainer, $model);
            $scheduledClass = $this->scheduledClass($account, $trainer, $location, $classType, '2026-06-10 10:00:00');
            $booking = $this->booking($account, $scheduledClass, $name);

            if ($name === 'free pass') {
                $this->passReservation($account, $scheduledClass, $booking, 0, 1);
            } elseif ($name === 'invalid pass sessions') {
                $this->passReservation($account, $scheduledClass, $booking, 10000, 0);
            } elseif ($name === 'unpaid direct payment') {
                $this->directPayment(
                    $account,
                    $booking,
                    10000,
                    status: CustomerPurchaseStatus::PaymentPending,
                );
            } elseif ($name === 'currency mismatch') {
                $this->passReservation($account, $scheduledClass, $booking, 10000, 1, 'USD');
            } elseif ($name === 'refund currency mismatch') {
                $payment = $this->directPayment($account, $booking, 10000);
                $this->refund($account, $payment, 1000, 'USD');
            } elseif ($name === 'tenant mismatch') {
                $otherAccount = Account::factory()->create();
                $this->passReservation($otherAccount, $scheduledClass, $booking, 10000, 1);
            }

            $result = $this->calculate($account, $trainer);
            $entry = $result['entries']->sole();

            $this->assertSame($case['expected'], $entry['amount_cents'], $name);
            $this->assertSame($case['reason'], $entry['reason_key'], $name);
            $this->assertSame($case['expected'] === null, $result['incomplete'], $name);

            if ($case['reason']) {
                $this->actingAs($owner)
                    ->get(route('dashboard.accounts.reports.trainers.salary', [
                        'account' => $account,
                        'trainer' => $trainer,
                        'date_from' => '2026-06-01',
                        'date_to' => '2026-06-30',
                    ]))
                    ->assertOk()
                    ->assertSee(__('app.'.$case['reason']));
            }
        }

        foreach ([false, true] as $payEmptyClasses) {
            [, $account, $trainer, $location, $classType] = $this->salaryContext(ScheduleKind::GroupClass);
            $model = $this->percentageModel($account, 5000, payEmptyClasses: $payEmptyClasses);
            $this->assign($account, $trainer, $model);
            $this->scheduledClass($account, $trainer, $location, $classType, '2026-06-10 10:00:00');

            $entry = $this->calculate($account, $trainer)['entries']->sole();

            $this->assertSame(0, $entry['amount_cents']);
            $this->assertSame(
                $payEmptyClasses ? null : 'salary_reason_empty_class',
                $entry['reason_key'],
            );
            $this->assertSame($payEmptyClasses ? 0 : null, $entry['class_value_cents']);
        }
    }

    /**
     * @return array{0: User, 1: Account, 2: Trainer, 3: Location, 4: ClassType}
     */
    private function salaryContext(ScheduleKind $scheduleKind): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);

        return [
            $owner,
            $account,
            Trainer::factory()->for($account)->create(),
            Location::factory()->for($account)->create(['timezone' => 'UTC']),
            $this->classType($account, $scheduleKind->value, $scheduleKind),
        ];
    }

    private function percentageModel(
        Account $account,
        int $basisPoints,
        bool $payEmptyClasses = true,
        ?int $minimumPayCents = null,
        ?int $maximumPayCents = null,
        ?ClassType $overrideClassType = null,
        ?int $overrideBasisPoints = null,
    ): SalaryModel {
        $model = SalaryModel::factory()->for($account)->create([
            'name' => 'Percentage '.$basisPoints.' '.fake()->unique()->numberBetween(1, 100000),
            'type' => SalaryModelType::PerClass->value,
        ]);
        $version = $model->versions()->create([
            'account_id' => $account->id,
            'effective_from' => '2026-01-01',
            'currency' => 'UAH',
            'counted_booking_statuses' => [ClassBookingStatus::Attended->value],
            'pay_empty_classes' => $payEmptyClasses,
        ]);
        $version->classRules()->create([
            'account_id' => $account->id,
            'is_default' => true,
            'formula_type' => SalaryClassFormulaType::ClassValuePercentage->value,
            'class_value_percentage_basis_points' => $basisPoints,
            'minimum_people' => 0,
            'included_people' => 0,
            'minimum_pay_cents' => $minimumPayCents,
            'maximum_pay_cents' => $maximumPayCents,
        ]);

        if ($overrideClassType && $overrideBasisPoints !== null) {
            $version->classRules()->create([
                'account_id' => $account->id,
                'class_type_id' => $overrideClassType->id,
                'class_type_name' => $overrideClassType->name,
                'is_default' => false,
                'formula_type' => SalaryClassFormulaType::ClassValuePercentage->value,
                'class_value_percentage_basis_points' => $overrideBasisPoints,
                'minimum_people' => 0,
                'included_people' => 0,
            ]);
        }

        return $model;
    }

    private function assign(Account $account, Trainer $trainer, SalaryModel $model): void
    {
        $trainer->salaryAssignments()->create([
            'account_id' => $account->id,
            'salary_model_id' => $model->id,
            'effective_from' => '2026-01-01',
        ]);
    }

    private function classType(Account $account, string $name, ScheduleKind $scheduleKind): ClassType
    {
        $activityDirection = ActivityDirection::factory()->for($account)->create();

        return ClassType::factory()
            ->for($account)
            ->for($activityDirection, 'activityDirection')
            ->create([
                'name' => $name,
                'schedule_kind' => $scheduleKind->value,
            ]);
    }

    private function scheduledClass(
        Account $account,
        Trainer $trainer,
        Location $location,
        ClassType $classType,
        string $startsAt,
        int $capacity = 10,
    ): ScheduledClass {
        $start = Carbon::parse($startsAt, 'UTC');
        $room = Room::factory()->for($account)->for($location)->create();

        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => $start,
                'ends_at' => $start->copy()->addHour(),
                'capacity' => $capacity,
            ]);
    }

    private function booking(Account $account, ScheduledClass $scheduledClass, string $customerName): ClassBooking
    {
        $customer = Customer::factory()->for($account)->create(['name' => $customerName]);

        return ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($customer)
            ->create(['status' => ClassBookingStatus::Attended->value]);
    }

    private function passReservation(
        Account $account,
        ScheduledClass $scheduledClass,
        ClassBooking $booking,
        int $priceCents,
        int $sessionsCount,
        string $currency = 'UAH',
    ): CustomerClassPassReservation {
        $customerClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($booking->customer)
            ->create([
                'class_pass_plan_id' => null,
                'price_cents' => $priceCents,
                'paid_amount_cents' => $priceCents,
                'currency' => $currency,
                'sessions_count' => $sessionsCount,
            ]);

        return CustomerClassPassReservation::factory()
            ->for($account)
            ->for($customerClassPass)
            ->for($booking)
            ->for($scheduledClass)
            ->create([
                'status' => CustomerClassPassReservationStatus::Used->value,
                'reserved_at' => $scheduledClass->starts_at->copy()->subDay(),
                'used_at' => $scheduledClass->ends_at,
            ]);
    }

    private function directPayment(
        Account $account,
        ClassBooking $booking,
        int $amountCents,
        string $currency = 'UAH',
        CustomerPurchaseStatus $status = CustomerPurchaseStatus::PaymentPaid,
    ): CustomerPurchase {
        return CustomerPurchase::factory()
            ->for($account)
            ->for($booking->customer)
            ->create([
                'class_pass_plan_id' => null,
                'class_booking_id' => $booking->id,
                'payment_source' => CustomerPurchase::SourceManualCashBooking,
                'status' => $status->value,
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'sessions_count' => 1,
                'paid_at' => $status === CustomerPurchaseStatus::PaymentPaid ? now() : null,
            ]);
    }

    private function refund(Account $account, CustomerPurchase $purchase, int $amountCents, string $currency = 'UAH'): void
    {
        CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($purchase, 'customerPurchase')
            ->create([
                'amount_cents' => $amountCents,
                'currency' => $currency,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function calculate(Account $account, Trainer $trainer): array
    {
        return app(TrainerSalaryCalculator::class)->forTrainer($account, $trainer, [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'location_id' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function salaryModelPayload(string $name, string $percentage): array
    {
        return [
            'name' => $name,
            'type' => SalaryModelType::PerClass->value,
            'effective_from' => '2026-07-01',
            'counted_booking_statuses' => [ClassBookingStatus::Attended->value],
            'pay_empty_classes' => '0',
            'rules' => [$this->percentageRule($percentage)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function percentageRule(string $percentage, ?ClassType $classType = null): array
    {
        return [
            'class_type_id' => $classType?->id,
            'is_default' => $classType === null ? '1' : '0',
            'formula_type' => SalaryClassFormulaType::ClassValuePercentage->value,
            'class_value_percentage' => $percentage,
        ];
    }
}
