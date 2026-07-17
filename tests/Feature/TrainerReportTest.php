<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrainerReportTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reports_are_available_to_owner_and_booking_staff(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $staff = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($staff, 'user')
            ->create([
                'role' => AccountRole::Receptionist->value,
                'permissions' => [StudioPermission::ManageBookings->value],
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee(__('app.reports'))
            ->assertSee(route('dashboard.accounts.reports.index', $account), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.index', $account))
            ->assertOk()
            ->assertSee(__('app.trainer_report_title'));

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.reports.trainers', $account))
            ->assertOk()
            ->assertSee(__('app.trainer_report_title'));
    }

    public function test_reports_are_forbidden_without_booking_permission(): void
    {
        $account = Account::factory()->create();
        $staff = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($staff, 'user')
            ->create([
                'role' => AccountRole::Receptionist->value,
                'permissions' => [],
            ]);
        $nonMember = User::factory()->create();

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.reports.index', $account))
            ->assertForbidden();

        $this->actingAs($nonMember)
            ->get(route('dashboard.accounts.reports.trainers', $account))
            ->assertForbidden();
    }

    public function test_trainer_report_counts_classes_and_people_by_period_location_and_tenant(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $center = Location::factory()->for($account)->create(['name' => 'Center']);
        $suburb = Location::factory()->for($account)->create(['name' => 'Suburb']);
        $alice = Trainer::factory()->for($account)->create(['name' => 'Alice Trainer']);
        $bob = Trainer::factory()->for($account)->create(['name' => 'Inactive Bob', 'is_active' => false]);
        $zero = Trainer::factory()->for($account)->create(['name' => 'Zero Trainer']);
        $otherTrainer = Trainer::factory()->for($otherAccount)->create(['name' => 'Other Studio Trainer']);

        $aliceClass = $this->scheduledClass($account, $alice, $center, '2026-06-10 10:00:00');
        $this->booking($account, $aliceClass, ClassBookingStatus::Booked, 'Booked Client');
        $this->booking($account, $aliceClass, ClassBookingStatus::Attended, 'Attended Client');
        $this->booking($account, $aliceClass, ClassBookingStatus::NoShow, 'No Show Client');
        $this->booking($account, $aliceClass, ClassBookingStatus::Cancelled, 'Cancelled Client');

        $privateLesson = $this->scheduledClass(
            $account,
            $alice,
            $center,
            '2026-06-10 12:00:00',
            scheduleKind: ScheduleKind::PrivateLesson,
        );
        $this->booking($account, $privateLesson, ClassBookingStatus::Booked, 'Private Lesson Client');
        $roomRental = $this->scheduledClass(
            $account,
            $alice,
            $center,
            '2026-06-10 14:00:00',
            scheduleKind: ScheduleKind::RoomRental,
        );
        $this->booking($account, $roomRental, ClassBookingStatus::Booked, 'Rental Client');

        $cancelledClass = $this->scheduledClass($account, $alice, $center, '2026-06-11 10:00:00', ScheduledClassStatus::Cancelled);
        $this->booking($account, $cancelledClass, ClassBookingStatus::Booked, 'Cancelled Class Client');
        $cancelledPrivateLesson = $this->scheduledClass(
            $account,
            $alice,
            $center,
            '2026-06-11 12:00:00',
            ScheduledClassStatus::Cancelled,
            ScheduleKind::PrivateLesson,
        );
        $this->booking($account, $cancelledPrivateLesson, ClassBookingStatus::Booked, 'Cancelled Private Lesson Client');
        $this->scheduledClass($account, $alice, $suburb, '2026-06-12 10:00:00');
        $this->scheduledClass($account, $bob, $center, '2026-06-13 10:00:00');
        $correctedClass = $this->scheduledClass($account, $alice, $center, '2026-06-14 10:00:00');
        $this->booking($account, $correctedClass, ClassBookingStatus::Booked, 'Corrected Client')
            ->update(['corrected_removed_at' => now()]);

        $otherLocation = Location::factory()->for($otherAccount)->create();
        $otherClass = $this->scheduledClass($otherAccount, $otherTrainer, $otherLocation, '2026-06-10 10:00:00');
        $this->booking($otherAccount, $otherClass, ClassBookingStatus::Booked, 'Other Tenant Client');

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers', [
                'account' => $account,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
                'location_id' => $center->id,
            ]));

        $response
            ->assertOk()
            ->assertSee('Alice Trainer')
            ->assertSee('Inactive Bob')
            ->assertSee('Zero Trainer')
            ->assertDontSee('Other Studio Trainer')
            ->assertSee(__('app.trainer_report_group_people_count'))
            ->assertSee(__('app.trainer_report_private_people_count'))
            ->assertSee('data-report-metrics="'.$alice->id.':3:1:2:1"', false)
            ->assertSee('data-report-metrics="'.$bob->id.':0:0:0:0"', false)
            ->assertSee('data-report-metrics="'.$zero->id.':0:0:0:0"', false)
            ->assertSee('data-private-lesson-count-actions', false)
            ->assertSee('data-filtered-history-link', false)
            ->assertSee('data-lucide="list-filter"', false);
    }

    public function test_trainer_report_excludes_classes_that_have_not_ended(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 10:30:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $trainer = Trainer::factory()->for($account)->create();
        $ongoingClass = $this->scheduledClass($account, $trainer, $location, '2026-06-10 10:00:00');
        $futureClass = $this->scheduledClass($account, $trainer, $location, '2026-06-11 10:00:00');
        $this->booking($account, $ongoingClass, ClassBookingStatus::Booked, 'Ongoing Client');
        $this->booking($account, $futureClass, ClassBookingStatus::Booked, 'Future Client');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers', [
                'account' => $account,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk()
            ->assertSee('data-report-metrics="'.$trainer->id.':0:0:0:0"', false);
    }

    public function test_private_lesson_details_keep_pass_amounts_without_aggregate_salary_basis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC', 'default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Main studio']);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Salary Trainer']);
        $firstLesson = $this->scheduledClass($account, $trainer, $location, '2026-06-10 10:00:00', scheduleKind: ScheduleKind::PrivateLesson);
        $firstLesson->update(['capacity' => 2]);
        $firstBooking = $this->booking($account, $firstLesson, ClassBookingStatus::Attended, 'Salary Client');
        $secondLesson = $this->scheduledClass($account, $trainer, $location, '2026-06-11 10:00:00', scheduleKind: ScheduleKind::PrivateLesson);
        $secondLesson->update(['capacity' => 3]);
        $secondBooking = ClassBooking::factory()
            ->for($account)
            ->for($secondLesson, 'scheduledClass')
            ->for($firstBooking->customer)
            ->create(['status' => ClassBookingStatus::Attended->value]);
        $missingLesson = $this->scheduledClass($account, $trainer, $location, '2026-06-12 10:00:00', scheduleKind: ScheduleKind::PrivateLesson);
        $missingLesson->update(['capacity' => 1]);
        $this->booking($account, $missingLesson, ClassBookingStatus::Attended, 'Missing Pass Client');
        $classPassPlan = ClassPassPlan::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'price_cents' => 1001,
            'sessions_count' => 3,
        ]);
        $customerClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($firstBooking->customer)
            ->for($classPassPlan)
            ->create([
                'plan_name' => 'Private salary pass',
                'price_cents' => 1001,
                'sessions_count' => 3,
                'currency' => 'UAH',
            ]);
        $this->reservation($account, $customerClassPass, $firstBooking, $firstLesson);
        $this->reservation($account, $customerClassPass, $secondBooking, $secondLesson);
        $usdLesson = $this->scheduledClass($account, $trainer, $location, '2026-06-13 10:00:00', scheduleKind: ScheduleKind::PrivateLesson);
        $usdBooking = ClassBooking::factory()
            ->for($account)
            ->for($usdLesson, 'scheduledClass')
            ->for($firstBooking->customer)
            ->create(['status' => ClassBookingStatus::Attended->value]);
        $usdPlan = ClassPassPlan::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'price_cents' => 5000,
            'sessions_count' => 1,
            'currency' => 'USD',
        ]);
        $usdClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($firstBooking->customer)
            ->for($usdPlan, 'classPassPlan')
            ->create([
                'plan_name' => 'USD private pass',
                'price_cents' => 5000,
                'sessions_count' => 1,
                'currency' => 'USD',
            ]);
        $this->reservation($account, $usdClassPass, $usdBooking, $usdLesson);

        $reportResponse = $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers', [
                'account' => $account,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]));

        $reportResponse
            ->assertOk()
            ->assertSee('data-report-metrics="'.$trainer->id.':4:4:0:7"', false)
            ->assertSee('data-group-people-count="0"', false)
            ->assertSee('data-private-people-count="7"', false)
            ->assertDontSee('data-private-lessons-amounts', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers.private-lessons', [
                'account' => $account,
                'trainer' => $trainer,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk()
            ->assertSee('Salary Client')
            ->assertSee('Private salary pass')
            ->assertSee('3.34 ₴')
            ->assertSee(__('app.amount_not_specified'));
    }

    public function test_private_lesson_amounts_and_salary_models_are_cashflow_protected(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $staff = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($staff, 'user')
            ->create([
                'role' => AccountRole::Receptionist->value,
                'permissions' => [StudioPermission::ManageBookings->value],
            ]);
        $location = Location::factory()->for($account)->create();
        $trainer = Trainer::factory()->for($account)->create();
        $lesson = $this->scheduledClass($account, $trainer, $location, '2026-06-10 10:00:00', scheduleKind: ScheduleKind::PrivateLesson);
        $this->booking($account, $lesson, ClassBookingStatus::Attended, 'Redacted Client');
        $filters = [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ];

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.reports.trainers', ['account' => $account, ...$filters]))
            ->assertOk()
            ->assertDontSee('data-private-lessons-amounts', false)
            ->assertDontSee(route('dashboard.accounts.salary-models.index', $account), false);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.reports.trainers.private-lessons', ['account' => $account, 'trainer' => $trainer, ...$filters]))
            ->assertOk()
            ->assertDontSee(__('app.amount_not_specified'));

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.salary-models.index', $account))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers', ['account' => $account, ...$filters]))
            ->assertOk()
            ->assertDontSee('data-private-lessons-amounts', false)
            ->assertSee(route('dashboard.accounts.salary-models.index', $account), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.salary-models.index', $account))
            ->assertOk()
            ->assertSee(__('app.salary_models_not_configured'));
    }

    public function test_private_lesson_details_are_paginated_twenty_per_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $trainer = Trainer::factory()->for($account)->create();

        foreach (range(1, 21) as $index) {
            $lesson = $this->scheduledClass($account, $trainer, $location, sprintf('2026-06-%02d 10:00:00', $index), scheduleKind: ScheduleKind::PrivateLesson);
            $this->booking($account, $lesson, ClassBookingStatus::Attended, 'Page Client '.$index);
        }

        $routeParameters = [
            'account' => $account,
            'trainer' => $trainer,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ];

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers.private-lessons', $routeParameters))
            ->assertOk()
            ->assertSee('Page Client 1')
            ->assertSee('Page Client 20')
            ->assertDontSee('Page Client 21')
            ->assertSee('page=2', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers.private-lessons', [...$routeParameters, 'page' => 2]))
            ->assertOk()
            ->assertSee('Page Client 21')
            ->assertDontSee('Page Client 20');
    }

    public function test_trainer_report_can_filter_people_by_booking_status(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Status Trainer']);
        $scheduledClass = $this->scheduledClass($account, $trainer, $location, '2026-06-10 10:00:00');
        $this->booking($account, $scheduledClass, ClassBookingStatus::Booked, 'Booked Client');
        $this->booking($account, $scheduledClass, ClassBookingStatus::Attended, 'Attended Client');
        $this->booking($account, $scheduledClass, ClassBookingStatus::NoShow, 'No Show Client');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers', [
                'account' => $account,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
                'booking_statuses' => [ClassBookingStatus::NoShow->value],
            ]))
            ->assertOk()
            ->assertSee('data-report-metrics="'.$trainer->id.':1:0:1:0"', false);
    }

    public function test_default_period_uses_account_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'America/New_York']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Timezone Trainer']);
        $beforeLocalMonth = $this->scheduledClass($account, $trainer, $location, '2026-07-01 03:30:00');
        $insideLocalMonth = $this->scheduledClass($account, $trainer, $location, '2026-07-01 04:30:00');
        $this->booking($account, $beforeLocalMonth, ClassBookingStatus::Booked, 'June Local Client');
        $this->booking($account, $insideLocalMonth, ClassBookingStatus::Booked, 'July Local Client');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers', $account))
            ->assertOk()
            ->assertSee('value="2026-07-01"', false)
            ->assertSee('data-report-metrics="'.$trainer->id.':1:0:1:0"', false);
    }

    private function scheduledClass(
        Account $account,
        Trainer $trainer,
        Location $location,
        string $startsAt,
        ScheduledClassStatus $status = ScheduledClassStatus::Scheduled,
        ScheduleKind $scheduleKind = ScheduleKind::GroupClass,
    ): ScheduledClass {
        $start = Carbon::parse($startsAt, 'UTC');
        $room = Room::factory()->for($account)->for($location)->create();
        $activityDirection = ActivityDirection::factory()->for($account)->create();
        $classType = ClassType::factory()
            ->for($account)
            ->for($activityDirection, 'activityDirection')
            ->create(['schedule_kind' => $scheduleKind->value]);

        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => $start,
                'ends_at' => $start->copy()->addHour(),
                'capacity' => $scheduleKind === ScheduleKind::PrivateLesson ? 1 : 10,
                'status' => $status->value,
            ]);
    }

    private function booking(Account $account, ScheduledClass $scheduledClass, ClassBookingStatus $status, string $customerName): ClassBooking
    {
        $customer = Customer::factory()->for($account)->create(['name' => $customerName]);

        return ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($customer)
            ->create(['status' => $status->value]);
    }

    private function reservation(
        Account $account,
        CustomerClassPass $customerClassPass,
        ClassBooking $classBooking,
        ScheduledClass $scheduledClass,
    ): CustomerClassPassReservation {
        return CustomerClassPassReservation::factory()
            ->for($account)
            ->for($customerClassPass)
            ->for($classBooking)
            ->for($scheduledClass)
            ->create([
                'status' => CustomerClassPassReservationStatus::Used->value,
                'used_at' => $scheduledClass->ends_at,
            ]);
    }
}
