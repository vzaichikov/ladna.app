<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\SalaryClassFormulaType;
use App\Enums\SalaryModelType;
use App\Enums\SalaryPeriodUnit;
use App\Enums\ScheduleKind;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\SalaryModel;
use App\Models\SalaryModelClassRule;
use App\Models\SalaryModelVersion;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerSalaryAssignment;
use App\Models\User;
use App\Support\Salary\TrainerSalaryCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalaryModelTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_create_version_and_bulk_assign_a_per_class_model(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $trainerOne = Trainer::factory()->for($account)->create();
        $trainerTwo = Trainer::factory()->for($account)->create();
        $classType = $this->classType($account, 'Pole');
        $tierClassType = $this->classType($account, 'Stretching');

        $response = $this->actingAs($owner)->post(
            route('dashboard.accounts.salary-models.store', $account),
            [
                'name' => 'Group trainers',
                'type' => SalaryModelType::PerClass->value,
                'effective_from' => '2026-07-01',
                'counted_booking_statuses' => [
                    ClassBookingStatus::Attended->value,
                    ClassBookingStatus::NoShow->value,
                ],
                'pay_empty_classes' => '0',
                'rules' => [
                    [
                        'class_type_id' => null,
                        'is_default' => '1',
                        'formula_type' => SalaryClassFormulaType::PerPerson->value,
                        'person_rate' => '100.50',
                        'minimum_people' => '2',
                    ],
                    [
                        'class_type_id' => $classType->id,
                        'is_default' => '0',
                        'formula_type' => SalaryClassFormulaType::BasePlusExtra->value,
                        'base_amount' => '500.00',
                        'included_people' => '3',
                        'extra_person_rate' => '75.25',
                    ],
                    [
                        'class_type_id' => $tierClassType->id,
                        'is_default' => '0',
                        'formula_type' => SalaryClassFormulaType::AttendanceTiers->value,
                        'tiers' => [
                            ['minimum_people' => '0', 'maximum_people' => '2', 'amount' => '400.00'],
                            ['minimum_people' => '3', 'maximum_people' => '', 'amount' => '650.00'],
                        ],
                    ],
                ],
            ],
        );

        $salaryModel = SalaryModel::query()->where('account_id', $account->id)->sole();
        $response->assertRedirect(route('dashboard.accounts.salary-models.edit', [$account, $salaryModel]));
        $this->assertSame(SalaryModelType::PerClass, $salaryModel->type);
        $this->assertDatabaseHas('salary_model_versions', [
            'salary_model_id' => $salaryModel->id,
            'currency' => 'UAH',
            'effective_from' => '2026-07-01',
        ]);
        $this->assertDatabaseHas('salary_model_class_rules', [
            'salary_model_version_id' => $salaryModel->versions()->sole()->id,
            'class_type_id' => null,
            'person_rate_cents' => 10050,
            'minimum_people' => 2,
        ]);
        $this->assertDatabaseHas('salary_model_class_rules', [
            'class_type_id' => $classType->id,
            'base_amount_cents' => 50000,
            'extra_person_rate_cents' => 7525,
        ]);
        $tierRule = SalaryModelClassRule::query()->where('class_type_id', $tierClassType->id)->sole();
        $this->assertDatabaseHas('salary_model_class_rule_tiers', [
            'salary_model_class_rule_id' => $tierRule->id,
            'minimum_people' => 3,
            'maximum_people' => null,
            'amount_cents' => 65000,
        ]);

        $this->actingAs($owner)->post(
            route('dashboard.accounts.salary-model-assignments.store', $account),
            [
                'salary_model_id' => $salaryModel->id,
                'trainer_ids' => [$trainerOne->id, $trainerTwo->id],
                'effective_from' => '2026-07-01',
            ],
        )->assertRedirect();

        $this->assertDatabaseHas('trainer_salary_assignments', [
            'account_id' => $account->id,
            'trainer_id' => $trainerOne->id,
            'salary_model_id' => $salaryModel->id,
            'effective_from' => '2026-07-01',
        ]);
        $this->assertSame(2, TrainerSalaryAssignment::query()->where('salary_model_id', $salaryModel->id)->count());
    }

    public function test_salary_models_link_is_in_settings_menu_between_segments_and_trainers(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee(__('app.salary_settings'))
            ->assertSeeInOrder([
                route('dashboard.accounts.class-pass-segments.index', $account),
                route('dashboard.accounts.salary-models.index', $account),
                route('dashboard.accounts.trainers.index', $account),
            ], false);
    }

    public function test_salary_model_writes_are_cashflow_and_tenant_protected(): void
    {
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $otherClassType = $this->classType($otherAccount, 'Other studio');
        $staff = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($staff, 'user')
            ->create([
                'role' => AccountRole::Receptionist->value,
                'permissions' => [StudioPermission::ManageBookings->value],
            ]);

        $payload = [
            'name' => 'Invalid model',
            'type' => SalaryModelType::PerClass->value,
            'effective_from' => '2026-07-01',
            'counted_booking_statuses' => [ClassBookingStatus::Attended->value],
            'rules' => [[
                'class_type_id' => $otherClassType->id,
                'is_default' => '1',
                'formula_type' => SalaryClassFormulaType::Flat->value,
                'flat_amount' => '100',
            ]],
        ];

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.salary-models.store', $account), $payload)
            ->assertForbidden();

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.salary-models.store', $account), $payload)
            ->assertSessionHasErrors(['rules.0.class_type_id', 'rules']);
        $this->assertDatabaseMissing('salary_models', ['account_id' => $account->id]);
    }

    public function test_updating_model_creates_effective_versions_and_supersedes_only_same_date(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $trainer = Trainer::factory()->for($account)->create();
        $createPayload = [
            'name' => 'Daily rate',
            'type' => SalaryModelType::FixedPeriod->value,
            'effective_from' => '2026-06-01',
            'period_unit' => SalaryPeriodUnit::Day->value,
            'amount' => '100.00',
        ];

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.salary-models.store', $account), $createPayload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $model = SalaryModel::query()->where('account_id', $account->id)->sole();
        $this->assign($account, $trainer, $model, '2026-06-01');

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.salary-models.update', [$account, $model]), [
                ...$createPayload,
                'effective_from' => '2026-06-16',
                'amount' => '200.00',
            ])
            ->assertRedirect();

        $this->assertSame(2, $model->versions()->count());
        $this->assertDatabaseHas('salary_model_versions', [
            'salary_model_id' => $model->id,
            'effective_from' => '2026-06-01',
            'amount_cents' => 10000,
            'superseded_at' => null,
        ]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.salary-models.update', [$account, $model]), [
                ...$createPayload,
                'effective_from' => '2026-06-16',
                'amount' => '300.00',
            ])
            ->assertRedirect();

        $this->assertSame(3, $model->versions()->count());
        $this->assertSame(
            1,
            $model->versions()
                ->whereDate('effective_from', '2026-06-16')
                ->whereNull('superseded_at')
                ->count(),
        );
        $this->assertSame(
            1,
            $model->versions()
                ->whereDate('effective_from', '2026-06-16')
                ->whereNotNull('superseded_at')
                ->count(),
        );

        $result = app(TrainerSalaryCalculator::class)->forTrainer($account, $trainer, [
            'date_from' => '2026-06-15',
            'date_to' => '2026-06-16',
            'location_id' => null,
        ]);
        $this->assertSame(40000, $result['amounts']['UAH']);
    }

    public function test_all_per_class_formulas_and_limits_are_calculated_from_counted_people(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create();
        $cases = [
            'flat' => [
                'formula' => SalaryClassFormulaType::Flat,
                'people' => 1,
                'rule' => ['flat_amount_cents' => 2500],
                'expected' => 2500,
            ],
            'minimum people' => [
                'formula' => SalaryClassFormulaType::PerPerson,
                'people' => 1,
                'rule' => ['person_rate_cents' => 1000, 'minimum_people' => 2],
                'expected' => 2000,
            ],
            'base plus extra' => [
                'formula' => SalaryClassFormulaType::BasePlusExtra,
                'people' => 4,
                'rule' => ['base_amount_cents' => 2000, 'included_people' => 2, 'extra_person_rate_cents' => 500],
                'expected' => 3000,
            ],
            'hourly plus extra' => [
                'formula' => SalaryClassFormulaType::HourlyPlusExtra,
                'people' => 4,
                'duration' => 90,
                'rule' => ['hourly_rate_cents' => 1000, 'included_people' => 2, 'extra_person_rate_cents' => 250],
                'expected' => 2000,
            ],
            'tiers' => [
                'formula' => SalaryClassFormulaType::AttendanceTiers,
                'people' => 4,
                'rule' => [],
                'tiers' => [
                    ['minimum_people' => 0, 'maximum_people' => 2, 'amount_cents' => 1000],
                    ['minimum_people' => 3, 'maximum_people' => null, 'amount_cents' => 4000],
                ],
                'expected' => 4000,
            ],
            'maximum limit' => [
                'formula' => SalaryClassFormulaType::PerPerson,
                'people' => 4,
                'rule' => ['person_rate_cents' => 1000, 'minimum_people' => 0, 'maximum_pay_cents' => 3500],
                'expected' => 3500,
            ],
        ];

        foreach ($cases as $index => $case) {
            $trainer = Trainer::factory()->for($account)->create(['name' => $index]);
            $model = $this->perClassModel($account, $case['formula'], $case['rule'], $case['tiers'] ?? []);
            $this->assign($account, $trainer, $model, '2026-06-01');
            $classType = $this->classType($account, $index);
            $scheduledClass = $this->scheduledClass(
                $account,
                $trainer,
                $location,
                $classType,
                '2026-06-10 10:00:00',
                $case['duration'] ?? 60,
            );

            foreach (range(1, $case['people']) as $person) {
                $this->booking($account, $scheduledClass, ClassBookingStatus::Attended, $index.' '.$person);
            }

            $result = app(TrainerSalaryCalculator::class)->forTrainer($account, $trainer, [
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
                'location_id' => null,
            ]);

            $this->assertSame($case['expected'], $result['amounts']['UAH'], $index);
            $this->assertFalse($result['incomplete'], $index);
        }
    }

    public function test_empty_class_switch_and_attendance_correction_recalculate_salary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $trainer = Trainer::factory()->for($account)->create();
        $location = Location::factory()->for($account)->create();
        $classType = $this->classType($account, 'Attendance');
        $model = $this->perClassModel(
            $account,
            SalaryClassFormulaType::Flat,
            ['flat_amount_cents' => 5000],
            payEmptyClasses: false,
        );
        $this->assign($account, $trainer, $model, '2026-06-01');
        $scheduledClass = $this->scheduledClass($account, $trainer, $location, $classType, '2026-06-10 10:00:00');
        $booking = $this->booking($account, $scheduledClass, ClassBookingStatus::Attended, 'Corrected');
        $calculator = app(TrainerSalaryCalculator::class);
        $filters = ['date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'location_id' => null];

        $this->assertSame(5000, $calculator->forTrainer($account, $trainer, $filters)['amounts']['UAH']);

        $booking->update(['status' => ClassBookingStatus::NoShow->value]);

        $result = $calculator->forTrainer($account, $trainer, $filters);
        $this->assertSame(0, $result['amounts']['UAH']);
        $this->assertSame('salary_reason_empty_class', $result['entries']->sole()['reason_key']);
    }

    public function test_fixed_month_salary_uses_calendar_day_proration_versions_and_ignores_location_filter(): void
    {
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $trainer = Trainer::factory()->for($account)->create();
        $location = Location::factory()->for($account)->create();
        $model = SalaryModel::factory()->for($account)->create([
            'name' => 'Monthly salary',
            'type' => SalaryModelType::FixedPeriod->value,
        ]);
        $this->fixedVersion($account, $model, '2026-06-01', 30000);
        $this->fixedVersion($account, $model, '2026-06-21', 60000);
        $this->assign($account, $trainer, $model, '2026-06-16');

        $result = app(TrainerSalaryCalculator::class)->forAccount($account, [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'location_id' => $location->id,
        ]);
        $trainerResult = $result['trainers']->get($trainer->id);

        $this->assertSame(25000, $trainerResult['amounts']['UAH']);
        $this->assertCount(2, $trainerResult['entries']);
        $this->assertTrue($result['fixed_ignores_location']);
    }

    public function test_salary_detail_is_cashflow_protected_and_lists_each_calculation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $staff = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($staff, 'user')
            ->create([
                'role' => AccountRole::Receptionist->value,
                'permissions' => [StudioPermission::ManageBookings->value],
            ]);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Detailed Trainer']);
        $location = Location::factory()->for($account)->create(['name' => 'Main location']);
        $classType = $this->classType($account, 'Detailed class');
        $model = $this->perClassModel($account, SalaryClassFormulaType::PerPerson, [
            'person_rate_cents' => 12000,
            'minimum_people' => 2,
        ]);
        $this->assign($account, $trainer, $model, '2026-06-01');
        $scheduledClass = $this->scheduledClass($account, $trainer, $location, $classType, '2026-06-10 10:00:00');
        $this->booking($account, $scheduledClass, ClassBookingStatus::Attended, 'One attendee');
        $parameters = [
            'account' => $account,
            'trainer' => $trainer,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ];

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.reports.trainers.salary', $parameters))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers.salary', $parameters))
            ->assertOk()
            ->assertSee('Detailed class')
            ->assertSee('Main location')
            ->assertSee('2 × 120.00 = 240.00')
            ->assertSee('240.00');

        $unassignedTrainer = Trainer::factory()->for($account)->create();
        $unassignedClass = $this->scheduledClass(
            $account,
            $unassignedTrainer,
            $location,
            $classType,
            '2026-06-11 10:00:00',
        );
        $this->booking($account, $unassignedClass, ClassBookingStatus::Attended, 'Unassigned attendee');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers.salary', [
                ...$parameters,
                'trainer' => $unassignedTrainer,
            ]))
            ->assertOk()
            ->assertSee(__('app.salary_reason_no_assignment'));
    }

    public function test_assigned_model_cannot_be_archived_and_salary_history_blocks_hard_deletion(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $trainer = Trainer::factory()->for($account)->create();
        $classType = $this->classType($account, 'Historical');
        $model = $this->perClassModel($account, SalaryClassFormulaType::Flat, ['flat_amount_cents' => 1000], classType: $classType);
        $this->assign($account, $trainer, $model, now('UTC')->subDay()->toDateString());

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.salary-models.archive', [$account, $model]))
            ->assertSessionHasErrors('salary_model');
        $this->assertNull($model->fresh()->archived_at);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.trainers.destroy', [$account, $trainer]))
            ->assertSessionHasErrors('trainer');
        $this->assertModelExists($trainer);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.group-classes.destroy', [$account, $classType]))
            ->assertSessionHasErrors('class_type');
        $this->assertModelExists($classType);
    }

    /**
     * @param  array<string, int|null>  $ruleAttributes
     * @param  array<int, array{minimum_people: int, maximum_people: int|null, amount_cents: int}>  $tiers
     */
    private function perClassModel(
        Account $account,
        SalaryClassFormulaType $formulaType,
        array $ruleAttributes,
        array $tiers = [],
        bool $payEmptyClasses = true,
        ?ClassType $classType = null,
    ): SalaryModel {
        $model = SalaryModel::factory()->for($account)->create([
            'name' => 'Model '.$formulaType->value.' '.fake()->unique()->numberBetween(1, 100000),
            'type' => SalaryModelType::PerClass->value,
        ]);
        $version = $model->versions()->create([
            'account_id' => $account->id,
            'effective_from' => '2026-01-01',
            'currency' => 'UAH',
            'counted_booking_statuses' => [ClassBookingStatus::Attended->value],
            'pay_empty_classes' => $payEmptyClasses,
        ]);
        $rule = $version->classRules()->create([
            'account_id' => $account->id,
            'class_type_id' => $classType?->id,
            'class_type_name' => $classType?->name,
            'is_default' => $classType === null,
            'formula_type' => $formulaType->value,
            'minimum_people' => 0,
            'included_people' => 0,
            ...$ruleAttributes,
        ]);

        foreach ($tiers as $tier) {
            $rule->tiers()->create(['account_id' => $account->id, ...$tier]);
        }

        return $model;
    }

    private function fixedVersion(
        Account $account,
        SalaryModel $model,
        string $effectiveFrom,
        int $amountCents,
    ): SalaryModelVersion {
        return $model->versions()->create([
            'account_id' => $account->id,
            'effective_from' => $effectiveFrom,
            'currency' => 'UAH',
            'period_unit' => SalaryPeriodUnit::Month->value,
            'amount_cents' => $amountCents,
        ]);
    }

    private function assign(Account $account, Trainer $trainer, SalaryModel $model, string $effectiveFrom): TrainerSalaryAssignment
    {
        return $trainer->salaryAssignments()->create([
            'account_id' => $account->id,
            'salary_model_id' => $model->id,
            'effective_from' => $effectiveFrom,
        ]);
    }

    private function classType(Account $account, string $name): ClassType
    {
        $activityDirection = ActivityDirection::factory()->for($account)->create();

        return ClassType::factory()
            ->for($account)
            ->for($activityDirection, 'activityDirection')
            ->create([
                'name' => $name,
                'schedule_kind' => ScheduleKind::GroupClass->value,
            ]);
    }

    private function scheduledClass(
        Account $account,
        Trainer $trainer,
        Location $location,
        ClassType $classType,
        string $startsAt,
        int $durationMinutes = 60,
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
                'ends_at' => $start->copy()->addMinutes($durationMinutes),
            ]);
    }

    private function booking(
        Account $account,
        ScheduledClass $scheduledClass,
        ClassBookingStatus $status,
        string $customerName,
    ): ClassBooking {
        $customer = Customer::factory()->for($account)->create(['name' => $customerName]);

        return ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($customer)
            ->create(['status' => $status->value]);
    }
}
