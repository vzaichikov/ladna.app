<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\SalaryClassFormulaType;
use App\Enums\SalaryModelType;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\CustomerPurchase;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Room;
use App\Models\SalaryModel;
use App\Models\ScheduledClass;
use App\Models\StudioExpense;
use App\Models\Trainer;
use App\Support\Finance\EarningsReportData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EarningsReportDataTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_earnings_use_completed_session_value_and_deduct_active_expenses_and_salary_by_currency(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'UTC'));

        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $groupTrainer = Trainer::factory()->for($account)->create(['name' => 'Group Trainer']);
        $otherTrainer = Trainer::factory()->for($account)->create(['name' => 'Other Trainer']);
        $this->assignFlatSalary($account, $groupTrainer, 20000);
        $this->assignFlatSalary($account, $otherTrainer, 0);
        $groupType = $this->classType($account, ScheduleKind::GroupClass);
        $rentalType = $this->classType($account, ScheduleKind::RoomRental);
        $privateType = $this->classType($account, ScheduleKind::PrivateLesson);

        $groupClass = $this->scheduledClass($account, $location, $groupTrainer, $groupType, '2026-07-10 10:00:00');
        $groupBooking = $this->booking($account, $groupClass, ClassBookingStatus::Attended);
        $this->reservation($account, $groupBooking, $groupClass, 100000, 4, 'UAH');
        CustomerPurchase::factory()->for($account)->for($groupBooking->customer)->for($location)->create([
            'class_pass_plan_id' => null,
            'class_booking_id' => null,
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'amount_cents' => 100000,
            'currency' => 'UAH',
            'paid_at' => '2026-07-01 10:00:00',
        ]);

        $rental = $this->scheduledClass($account, $location, $otherTrainer, $rentalType, '2026-07-11 10:00:00');
        $rentalBooking = $this->booking($account, $rental, ClassBookingStatus::Attended);
        $this->directPayment($account, $location, $rentalBooking, 60000, 'UAH');

        $privateLesson = $this->scheduledClass($account, $location, $otherTrainer, $privateType, '2026-07-12 10:00:00');
        $privateBooking = $this->booking($account, $privateLesson, ClassBookingStatus::Attended);
        $this->directPayment($account, $location, $privateBooking, 3000, 'USD');

        $cancelledClass = $this->scheduledClass(
            $account,
            $location,
            $groupTrainer,
            $groupType,
            '2026-07-13 10:00:00',
            ScheduledClassStatus::Cancelled,
        );
        $cancelledClassBooking = $this->booking($account, $cancelledClass, ClassBookingStatus::Attended);
        $this->directPayment($account, $location, $cancelledClassBooking, 40000, 'UAH');

        $cancelledBookingClass = $this->scheduledClass($account, $location, $otherTrainer, $privateType, '2026-07-14 10:00:00');
        $cancelledBooking = $this->booking($account, $cancelledBookingClass, ClassBookingStatus::Cancelled);
        $this->directPayment($account, $location, $cancelledBooking, 41000, 'UAH');

        $correctedClass = $this->scheduledClass($account, $location, $otherTrainer, $privateType, '2026-07-15 10:00:00');
        $correctedBooking = $this->booking($account, $correctedClass, ClassBookingStatus::Attended);
        $correctedBooking->update(['corrected_removed_at' => '2026-07-15 12:00:00']);
        $this->directPayment($account, $location, $correctedBooking, 42000, 'UAH');

        $ongoingClass = $this->scheduledClass($account, $location, $otherTrainer, $privateType, '2026-08-01 11:30:00');
        $ongoingClass->update(['ends_at' => '2026-08-01 12:30:00']);
        $ongoingBooking = $this->booking($account, $ongoingClass, ClassBookingStatus::Attended);
        $this->directPayment($account, $location, $ongoingBooking, 43000, 'UAH');

        $category = ExpenseCategory::factory()->for($account)->create();
        $this->expense($account, $category, $location, 10000, 'UAH');
        $this->expense($account, $category, $location, 500, 'USD');
        $this->expense($account, $category, $location, 99999, 'UAH', voided: true);

        $report = $this->report(
            $account,
            Carbon::parse('2026-07-01 00:00:00', 'UTC'),
            Carbon::parse('2026-08-01 23:59:59', 'UTC'),
        );

        $this->assertSame(['UAH' => 25000, 'USD' => 3000], $report['totals']['lesson_revenue']);
        $this->assertSame(['UAH' => 60000], $report['totals']['rental_revenue']);
        $this->assertSame(['UAH' => 85000, 'USD' => 3000], $report['totals']['revenue']);
        $this->assertSame(['UAH' => 10000, 'USD' => 500], $report['totals']['expenses']);
        $this->assertSame(['UAH' => 20000], $report['totals']['salary']);
        $this->assertSame(['UAH' => 55000, 'USD' => 2500], $report['totals']['earnings']);
        $this->assertFalse($report['salary_incomplete']);
        $this->assertSame(
            [$groupClass->id, $rental->id, $privateLesson->id, $cancelledBookingClass->id, $correctedClass->id],
            $report['rows']->pluck('scheduled_class.id')->sort()->values()->all(),
        );
    }

    public function test_intraday_epoch_boundary_excludes_earlier_class_and_marks_missing_salary_incomplete(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 23:00:00', 'UTC'));

        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $trainer = Trainer::factory()->for($account)->create();
        $classType = $this->classType($account, ScheduleKind::GroupClass);
        $beforeBoundary = $this->scheduledClass($account, $location, $trainer, $classType, '2026-07-10 10:00:00');
        $beforeBooking = $this->booking($account, $beforeBoundary, ClassBookingStatus::Attended);
        $this->directPayment($account, $location, $beforeBooking, 10000, 'UAH');
        $afterBoundary = $this->scheduledClass($account, $location, $trainer, $classType, '2026-07-10 12:00:00');
        $afterBooking = $this->booking($account, $afterBoundary, ClassBookingStatus::Attended);
        $this->directPayment($account, $location, $afterBooking, 20000, 'UAH');

        $report = $this->report(
            $account,
            Carbon::parse('2026-07-10 12:00:00', 'UTC'),
            Carbon::parse('2026-07-31 23:59:59', 'UTC'),
        );

        $this->assertSame([$afterBoundary->id], $report['rows']->pluck('scheduled_class.id')->all());
        $this->assertSame(['UAH' => 20000], $report['totals']['revenue']);
        $this->assertSame([], $report['totals']['salary']);
        $this->assertSame(['UAH' => 20000], $report['totals']['earnings']);
        $this->assertTrue($report['salary_incomplete']);
    }

    public function test_salary_incomplete_is_recalculated_after_intraday_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 23:00:00', 'UTC'));

        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $trainer = Trainer::factory()->for($account)->create();
        $uncoveredType = $this->classType($account, ScheduleKind::GroupClass);
        $coveredType = $this->classType($account, ScheduleKind::GroupClass);
        $model = SalaryModel::factory()->for($account)->create(['type' => SalaryModelType::PerClass->value]);
        $version = $model->versions()->create([
            'account_id' => $account->id,
            'effective_from' => '2026-01-01',
            'currency' => 'UAH',
            'counted_booking_statuses' => [ClassBookingStatus::Attended->value],
            'pay_empty_classes' => false,
        ]);
        $version->classRules()->create([
            'account_id' => $account->id,
            'class_type_id' => $coveredType->id,
            'class_type_name' => $coveredType->name,
            'is_default' => false,
            'formula_type' => SalaryClassFormulaType::Flat->value,
            'flat_amount_cents' => 5000,
            'minimum_people' => 0,
            'included_people' => 0,
        ]);
        $trainer->salaryAssignments()->create([
            'account_id' => $account->id,
            'salary_model_id' => $model->id,
            'effective_from' => '2026-01-01',
        ]);

        $beforeBoundary = $this->scheduledClass($account, $location, $trainer, $uncoveredType, '2026-07-10 10:00:00');
        $this->booking($account, $beforeBoundary, ClassBookingStatus::Attended);
        $afterBoundary = $this->scheduledClass($account, $location, $trainer, $coveredType, '2026-07-10 12:00:00');
        $this->booking($account, $afterBoundary, ClassBookingStatus::Attended);

        $report = $this->report(
            $account,
            Carbon::parse('2026-07-10 12:00:00', 'UTC'),
            Carbon::parse('2026-07-31 23:59:59', 'UTC'),
        );

        $this->assertSame(['UAH' => 5000], $report['totals']['salary']);
        $this->assertFalse($report['salary_incomplete']);
    }

    private function assignFlatSalary(Account $account, Trainer $trainer, int $amountCents): void
    {
        $model = SalaryModel::factory()->for($account)->create([
            'type' => SalaryModelType::PerClass->value,
        ]);
        $version = $model->versions()->create([
            'account_id' => $account->id,
            'effective_from' => '2026-01-01',
            'currency' => 'UAH',
            'counted_booking_statuses' => [ClassBookingStatus::Attended->value],
            'pay_empty_classes' => false,
        ]);
        $version->classRules()->create([
            'account_id' => $account->id,
            'is_default' => true,
            'formula_type' => SalaryClassFormulaType::Flat->value,
            'flat_amount_cents' => $amountCents,
            'minimum_people' => 0,
            'included_people' => 0,
        ]);
        $trainer->salaryAssignments()->create([
            'account_id' => $account->id,
            'salary_model_id' => $model->id,
            'effective_from' => '2026-01-01',
        ]);
    }

    private function classType(Account $account, ScheduleKind $scheduleKind): ClassType
    {
        $activityDirection = ActivityDirection::factory()->for($account)->create();

        return ClassType::factory()
            ->for($account)
            ->for($activityDirection, 'activityDirection')
            ->create(['schedule_kind' => $scheduleKind->value]);
    }

    private function scheduledClass(
        Account $account,
        Location $location,
        Trainer $trainer,
        ClassType $classType,
        string $startsAt,
        ScheduledClassStatus $status = ScheduledClassStatus::Scheduled,
    ): ScheduledClass {
        $room = Room::factory()->for($account)->for($location)->create();
        $start = Carbon::parse($startsAt, 'UTC');

        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($trainer)
            ->for($classType)
            ->create([
                'starts_at' => $start,
                'ends_at' => $start->copy()->addHour(),
                'status' => $status->value,
            ]);
    }

    private function booking(Account $account, ScheduledClass $scheduledClass, ClassBookingStatus $status): ClassBooking
    {
        $customer = Customer::factory()->for($account)->create();

        return ClassBooking::factory()->for($account)->for($scheduledClass)->for($customer)->create([
            'status' => $status->value,
        ]);
    }

    private function reservation(
        Account $account,
        ClassBooking $booking,
        ScheduledClass $scheduledClass,
        int $priceCents,
        int $sessionsCount,
        string $currency,
    ): CustomerClassPassReservation {
        $classPass = CustomerClassPass::factory()->for($account)->for($booking->customer)->create([
            'class_pass_plan_id' => null,
            'price_cents' => $priceCents,
            'paid_amount_cents' => $priceCents,
            'currency' => $currency,
            'sessions_count' => $sessionsCount,
        ]);

        return CustomerClassPassReservation::factory()
            ->for($account)
            ->for($classPass)
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
        Location $location,
        ClassBooking $booking,
        int $amountCents,
        string $currency,
    ): CustomerPurchase {
        return CustomerPurchase::factory()->for($account)->for($booking->customer)->for($location)->create([
            'class_pass_plan_id' => null,
            'class_booking_id' => $booking->id,
            'payment_source' => CustomerPurchase::SourceManualCashBooking,
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'paid_at' => $booking->scheduledClass->ends_at,
        ]);
    }

    private function expense(
        Account $account,
        ExpenseCategory $category,
        Location $location,
        int $amountCents,
        string $currency,
        bool $voided = false,
    ): StudioExpense {
        return StudioExpense::factory()->for($account)->for($category, 'category')->create([
            'location_id' => $location->id,
            'expense_location_id' => $location->id,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'occurred_at' => '2026-07-20 10:00:00',
            'voided_at' => $voided ? '2026-07-20 11:00:00' : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(Account $account, Carbon $startsAt, Carbon $endsAt): array
    {
        return app(EarningsReportData::class)->forAccount(
            $account,
            [
                'date_from' => $startsAt->toDateString(),
                'date_to' => $endsAt->toDateString(),
                'location_id' => null,
            ],
            $startsAt,
            $endsAt,
        );
    }
}
