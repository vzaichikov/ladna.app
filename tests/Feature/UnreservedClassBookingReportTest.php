<?php

namespace Tests\Feature;

use App\Actions\RecordCustomerPurchaseRefund;
use App\Actions\RecordManualClassBookingPayment;
use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassBookingPaymentWaiver;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchaseRefund;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use App\Support\ScheduledClassFocus;
use App\Support\StudioDashboardData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UnreservedClassBookingReportTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_report_dashboard_and_location_filter_share_central_issue_rules(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00', 'UTC'));

        $context = $this->context();
        $inactiveLocation = Location::factory()->for($context['account'])->create([
            'name' => 'Archive Studio',
            'timezone' => 'UTC',
            'is_active' => false,
        ]);
        $inactiveRoom = Room::factory()->for($context['account'])->for($inactiveLocation)->create(['name' => 'Archive Hall']);
        $additionalTrainer = Trainer::factory()->for($context['account'])->create(['name' => 'Additional Trainer']);

        $bookedClass = $this->scheduledClass($context, 'Booked issue', '2026-08-27 10:00:00');
        $bookedClass->additionalTrainers()->attach($additionalTrainer->id, ['account_id' => $context['account']->id]);
        $this->booking($context, $bookedClass, ClassBookingStatus::Booked, 'Booked Issue Customer');
        $attendedClass = $this->scheduledClass(
            $context,
            'Attended inactive issue',
            '2026-08-25 10:00:00',
            location: $inactiveLocation,
            room: $inactiveRoom,
            trainer: null,
        );
        $this->booking($context, $attendedClass, ClassBookingStatus::Attended, 'Attended Issue Customer');

        $noShowClass = $this->scheduledClass($context, 'No show closed', '2026-08-24 10:00:00');
        $noShowBooking = $this->booking($context, $noShowClass, ClassBookingStatus::Booked, 'No Show Customer');
        app(RecordManualClassBookingPayment::class)->execute($context['account'], $noShowBooking, 25000, $context['owner']);
        $noShowBooking->update(['status' => ClassBookingStatus::NoShow->value]);
        $rentalType = ClassType::factory()->for($context['account'])->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
        ]);
        $unpaidNoShowRentalClass = $this->scheduledClass($context, 'No show rental closed', '2026-08-24 12:00:00');
        $unpaidNoShowRentalClass->update(['class_type_id' => $rentalType->id]);
        $this->booking(
            $context,
            $unpaidNoShowRentalClass,
            ClassBookingStatus::NoShow,
            'Unpaid No Show Rental Customer',
            skip: true,
        );
        $paidBooking = $this->booking($context, $this->scheduledClass($context, 'Cash paid', '2026-08-28 10:00:00'), ClassBookingStatus::Booked, 'Cash Paid Customer');
        app(RecordManualClassBookingPayment::class)->execute($context['account'], $paidBooking, 25000, $context['owner']);
        $refundedBooking = $this->booking($context, $this->scheduledClass($context, 'Cash refunded', '2026-08-28 12:00:00'), ClassBookingStatus::Booked, 'Cash Refunded Customer');
        $refundedPayment = app(RecordManualClassBookingPayment::class)->execute($context['account'], $refundedBooking, 25000, $context['owner']);
        app(RecordCustomerPurchaseRefund::class)->execute(
            $context['account'],
            $refundedPayment,
            CustomerPurchaseRefund::MethodCash,
            $context['location'],
            25000,
            now(),
            $context['owner'],
            'Fully refunded test payment.',
            'unreserved-report-refund-'.$refundedBooking->id,
        );

        $waivedBooking = $this->booking($context, $this->scheduledClass($context, 'Waived', '2026-08-29 10:00:00'), ClassBookingStatus::Booked, 'Waived Customer');
        ClassBookingPaymentWaiver::factory()->for($context['account'])->create(['class_booking_id' => $waivedBooking->id]);

        foreach ([CustomerClassPassReservationStatus::Reserved, CustomerClassPassReservationStatus::Used] as $reservationStatus) {
            $reservedClass = $this->scheduledClass($context, 'Pass '.$reservationStatus->value, '2026-08-30 10:00:00');
            $reservedBooking = $this->booking(
                $context,
                $reservedClass,
                $reservationStatus === CustomerClassPassReservationStatus::Used ? ClassBookingStatus::Attended : ClassBookingStatus::Booked,
                'Pass '.$reservationStatus->value.' Customer',
            );
            $this->reserve($context, $reservedBooking, $reservationStatus);
        }

        $this->booking($context, $this->scheduledClass($context, 'Skipped', '2026-08-31 10:00:00'), ClassBookingStatus::Booked, 'Skipped Customer', skip: true);
        $this->booking($context, $this->scheduledClass($context, 'Cancelled booking', '2026-09-01 10:00:00'), ClassBookingStatus::Cancelled, 'Cancelled Customer');
        $corrected = $this->booking($context, $this->scheduledClass($context, 'Corrected', '2026-09-02 10:00:00'), ClassBookingStatus::Booked, 'Corrected Customer');
        $corrected->update(['corrected_removed_at' => now()]);
        $this->booking(
            $context,
            $this->scheduledClass($context, 'Cancelled class', '2026-09-03 10:00:00', status: ScheduledClassStatus::Cancelled),
            ClassBookingStatus::Booked,
            'Cancelled Class Customer',
        );

        $otherContext = $this->context();
        $this->booking($otherContext, $this->scheduledClass($otherContext, 'Other tenant', '2026-09-04 10:00:00'), ClassBookingStatus::Booked, 'Other Tenant Customer');

        $dashboardProblems = collect(app(StudioDashboardData::class)->forAccount($context['account'], $context['owner'])['ownerDashboard']['problems'])
            ->keyBy('key');
        $this->assertSame(2, $dashboardProblems['unreserved_bookings']['count']);
        $this->assertSame(
            route('dashboard.accounts.reports.unreserved-class-bookings', $context['account']),
            $dashboardProblems['unreserved_bookings']['url'],
        );

        $reportUrl = route('dashboard.accounts.reports.unreserved-class-bookings', $context['account']);
        $response = $this->withCookie('ladna_working_location_'.$context['account']->id, (string) $context['location']->id)
            ->actingAs($context['owner'])
            ->get($reportUrl);

        $response
            ->assertOk()
            ->assertViewHas('bookings', fn ($bookings): bool => $bookings->total() === 2)
            ->assertSee('Booked Issue Customer')
            ->assertSee('Attended Issue Customer')
            ->assertSee('Primary Trainer')
            ->assertSee('Additional Trainer')
            ->assertSee(__('app.trainer_not_assigned'))
            ->assertSee('Archive Studio')
            ->assertSee(__('app.inactive'))
            ->assertDontSee('No Show Customer')
            ->assertDontSee('Unpaid No Show Rental Customer')
            ->assertDontSee('Cash Paid Customer')
            ->assertDontSee('Cash Refunded Customer')
            ->assertDontSee('Waived Customer')
            ->assertDontSee('Pass reserved Customer')
            ->assertDontSee('Pass used Customer')
            ->assertDontSee('Skipped Customer')
            ->assertDontSee('Cancelled Customer')
            ->assertDontSee('Corrected Customer')
            ->assertDontSee('Cancelled Class Customer')
            ->assertDontSee('Other Tenant Customer');

        $this->actingAs($context['owner'])
            ->get($reportUrl.'?location_id='.$context['location']->id)
            ->assertOk()
            ->assertViewHas('bookings', fn ($bookings): bool => $bookings->total() === 1)
            ->assertSee('Booked Issue Customer')
            ->assertDontSee('Attended Issue Customer');

        $this->actingAs($context['owner'])
            ->get($reportUrl.'?location_id='.$inactiveLocation->id)
            ->assertOk()
            ->assertViewHas('bookings', fn ($bookings): bool => $bookings->total() === 1)
            ->assertSee('Attended Issue Customer')
            ->assertDontSee('Booked Issue Customer');

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.reports.index', $context['account']))
            ->assertOk()
            ->assertSee(__('app.unreserved_class_bookings_report_title'))
            ->assertSee($reportUrl, false);

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.scheduled-classes-history.index', [
                'account' => $context['account'],
                'scheduled_class' => $noShowClass->id,
            ]))
            ->assertOk()
            ->assertSee('No Show Customer')
            ->assertSee(__('app.class_booking_payment'))
            ->assertDontSee(__('app.no_matching_class_pass_alert'))
            ->assertDontSee(__('app.unpaid_class_booking_payment_alert'));

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.scheduled-classes-history.index', [
                'account' => $context['account'],
                'scheduled_class' => $unpaidNoShowRentalClass->id,
            ]))
            ->assertOk()
            ->assertSee('Unpaid No Show Rental Customer')
            ->assertDontSee(__('app.no_matching_class_pass_alert'))
            ->assertDontSee(__('app.unpaid_class_booking_payment_alert'));
    }

    public function test_report_paginates_twenty_bookings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00', 'UTC'));
        $context = $this->context();
        $scheduledClass = $this->scheduledClass($context, 'Pagination class', '2026-08-27 10:00:00');

        foreach (range(1, 21) as $index) {
            $this->booking($context, $scheduledClass, ClassBookingStatus::Booked, sprintf('Issue Customer %02d', $index));
        }

        $url = route('dashboard.accounts.reports.unreserved-class-bookings', $context['account']);

        $this->actingAs($context['owner'])
            ->get($url)
            ->assertOk()
            ->assertViewHas('bookings', fn ($bookings): bool => $bookings->total() === 21 && $bookings->count() === 20)
            ->assertSee('Issue Customer 01')
            ->assertDontSee('Issue Customer 21');

        $this->actingAs($context['owner'])
            ->get($url.'?page=2')
            ->assertOk()
            ->assertViewHas('bookings', fn ($bookings): bool => $bookings->total() === 21 && $bookings->count() === 1)
            ->assertSee('Issue Customer 21');
    }

    public function test_report_rows_focus_exact_current_or_history_class_with_tenant_and_permission_guards(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00', 'UTC'));
        $context = $this->context();
        $otherLocation = Location::factory()->for($context['account'])->create(['timezone' => 'UTC']);
        $otherRoom = Room::factory()->for($context['account'])->for($otherLocation)->create();
        $futureClass = $this->scheduledClass($context, 'Exact future class', '2026-08-27 10:00:00');
        $pastClass = $this->scheduledClass($context, 'Exact past class', '2026-08-25 10:00:00');
        $otherClass = $this->scheduledClass($context, 'Unrelated class', '2026-08-27 12:00:00', location: $otherLocation, room: $otherRoom);
        $this->booking($context, $futureClass, ClassBookingStatus::Booked, 'Future Issue');
        $this->booking($context, $pastClass, ClassBookingStatus::Attended, 'Past Issue');
        $this->booking($context, $otherClass, ClassBookingStatus::Booked, 'Other Issue');

        $focus = app(ScheduledClassFocus::class);
        $report = $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.reports.unreserved-class-bookings', $context['account']));
        $report
            ->assertOk()
            ->assertSee($focus->url($context['account'], $futureClass), false)
            ->assertSee($focus->url($context['account'], $pastClass), false);

        $this->withCookie('ladna_working_location_'.$context['account']->id, (string) $otherLocation->id)
            ->actingAs($context['owner'])
            ->get(route('dashboard.accounts.scheduled-classes.index', [
                'account' => $context['account'],
                'scheduled_class' => $futureClass->id,
            ]))
            ->assertOk()
            ->assertSee(__('app.focused_scheduled_class'))
            ->assertSee('Exact future class')
            ->assertDontSee('Unrelated class');

        $historyUrl = route('dashboard.accounts.scheduled-classes-history.index', [
            'account' => $context['account'],
            'scheduled_class' => $pastClass->id,
        ]);
        $this->actingAs($context['owner'])
            ->get($historyUrl)
            ->assertOk()
            ->assertSee(__('app.focused_scheduled_class'))
            ->assertSee('Exact past class')
            ->assertSee(__('app.unlock_closed_class_corrections'))
            ->assertDontSee('Unrelated class');

        $trainerUser = User::factory()->create();
        AccountMembership::factory()->for($context['account'])->for($trainerUser, 'user')->create([
            'role' => AccountRole::Trainer->value,
            'permissions' => [],
        ]);
        $this->actingAs($trainerUser)
            ->get($historyUrl)
            ->assertOk()
            ->assertSee('Exact past class')
            ->assertDontSee(__('app.unlock_closed_class_corrections'));

        $foreignContext = $this->context();
        $foreignClass = $this->scheduledClass($foreignContext, 'Foreign class', '2026-08-27 10:00:00');

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.scheduled-classes.index', [
                'account' => $context['account'],
                'scheduled_class' => $foreignClass->id,
            ]))
            ->assertNotFound();
        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.scheduled-classes-history.index', [
                'account' => $context['account'],
                'scheduled_class' => $foreignClass->id,
            ]))
            ->assertNotFound();

        $this->actingAs($trainerUser)
            ->get(route('dashboard.accounts.reports.unreserved-class-bookings', $context['account']))
            ->assertForbidden();
    }

    /**
     * @return array{owner: User, account: Account, location: Location, room: Room, classType: ClassType, trainer: Trainer}
     */
    private function context(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC', 'default_language' => 'en']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Main Studio', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Main Hall']);
        $activityDirection = ActivityDirection::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->for($activityDirection)->create([
            'name' => 'Pole class',
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Primary Trainer']);

        return compact('owner', 'account', 'location', 'room', 'classType', 'trainer');
    }

    /**
     * @param  array{account: Account, location: Location, room: Room, classType: ClassType, trainer: Trainer}  $context
     */
    private function scheduledClass(
        array $context,
        string $title,
        string $startsAt,
        ScheduledClassStatus $status = ScheduledClassStatus::Scheduled,
        ?Location $location = null,
        ?Room $room = null,
        Trainer|false|null $trainer = false,
    ): ScheduledClass {
        $location ??= $context['location'];
        $room ??= $context['room'];
        $assignedTrainer = $trainer === false ? $context['trainer'] : $trainer;

        return ScheduledClass::factory()
            ->for($context['account'])
            ->for($location)
            ->for($room)
            ->for($context['classType'])
            ->create([
                'trainer_id' => $assignedTrainer?->id,
                'title' => $title,
                'starts_at' => Carbon::parse($startsAt, 'UTC'),
                'ends_at' => Carbon::parse($startsAt, 'UTC')->addHour(),
                'status' => $status->value,
            ]);
    }

    /**
     * @param  array{account: Account, owner: User}  $context
     */
    private function booking(
        array $context,
        ScheduledClass $scheduledClass,
        ClassBookingStatus $status,
        string $customerName,
        bool $skip = false,
    ): ClassBooking {
        $customer = Customer::factory()->for($context['account'])->create(['name' => $customerName]);

        return ClassBooking::factory()
            ->for($context['account'])
            ->for($scheduledClass)
            ->for($customer)
            ->for($context['owner'], 'bookedBy')
            ->create([
                'status' => $status->value,
                'attended_at' => $status === ClassBookingStatus::Attended ? $scheduledClass->starts_at : null,
                'skip_class_pass_reservation' => $skip,
            ]);
    }

    /**
     * @param  array{account: Account}  $context
     */
    private function reserve(array $context, ClassBooking $booking, CustomerClassPassReservationStatus $status): void
    {
        $classPass = CustomerClassPass::factory()
            ->for($context['account'])
            ->for($booking->customer)
            ->for(ClassPassPlan::factory()->for($context['account']), 'classPassPlan')
            ->create();

        $classPass->reservations()->create([
            'account_id' => $context['account']->id,
            'class_booking_id' => $booking->id,
            'scheduled_class_id' => $booking->scheduled_class_id,
            'status' => $status->value,
            'reserved_at' => now()->subDay(),
            'used_at' => $status === CustomerClassPassReservationStatus::Used ? now() : null,
        ]);
    }
}
