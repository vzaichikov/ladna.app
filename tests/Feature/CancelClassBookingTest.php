<?php

namespace Tests\Feature;

use App\Actions\CancelClassBooking;
use App\Actions\IssueCustomerClassPass;
use App\Actions\ReconcileCustomerClassPassForBooking;
use App\Actions\ReserveCustomerClassPassForBooking;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Support\ClassBookingCancellationWindow;
use App\Support\CustomerNotifications\ClassBookingNotificationCoordinator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class CancelClassBookingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reserved_booking_is_released_and_kept_for_history(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context();
        $booking = $this->booking($context, '2026-07-26 10:00:00');
        $reservation = app(ReserveCustomerClassPassForBooking::class)->execute($booking);

        $cancelledBooking = app(CancelClassBooking::class)->execute($booking);

        $this->assertModelExists($cancelledBooking);
        $this->assertSame(ClassBookingStatus::Cancelled, $cancelledBooking->status);
        $this->assertNull($cancelledBooking->attended_at);
        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation?->fresh()->status);
        $this->assertNull($reservation?->fresh()->used_at);
        $this->assertNotNull($reservation?->fresh()->released_at);
        $this->assertSame(0, $context['pass']->fresh()->reserved_sessions_count);
        $this->assertSame(0, $context['pass']->fresh()->used_sessions_count);
        $this->assertSame(1, $context['pass']->fresh()->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_used_booking_is_released_and_reopens_used_up_pass(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context();
        $booking = $this->booking($context, '2026-07-26 10:00:00', ClassBookingStatus::Attended);
        app(ReconcileCustomerClassPassForBooking::class)->execute($booking);

        $this->assertSame(CustomerClassPassStatus::UsedUp, $context['pass']->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $booking->classPassReservation()->firstOrFail()->status);

        app(CancelClassBooking::class)->execute($booking);

        $reservation = $booking->classPassReservation()->firstOrFail();
        $customerClassPass = $context['pass']->fresh();

        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation->status);
        $this->assertSame(CustomerClassPassStatus::Active, $customerClassPass->status);
        $this->assertTrue($customerClassPass->is_active);
        $this->assertNull($customerClassPass->opened_at);
        $this->assertNull($customerClassPass->expires_at);
        $this->assertNull($customerClassPass->closed_at);
        $this->assertSame(1, $customerClassPass->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_released_cancellation_is_idempotent_and_notifies_once(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context();
        $booking = $this->booking($context, '2026-07-26 10:00:00');
        $reservation = app(ReserveCustomerClassPassForBooking::class)->execute($booking);
        $notifications = Mockery::mock(ClassBookingNotificationCoordinator::class);
        $notifications->shouldReceive('bookingCancelled')->once();
        $action = new CancelClassBooking(
            app(ClassBookingCancellationWindow::class),
            app(ReconcileCustomerClassPassForBooking::class),
            $notifications,
        );

        $action->execute($booking);
        $releasedAt = $reservation?->fresh()->released_at;
        $action->execute($booking->fresh());

        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation?->fresh()->status);
        $this->assertTrue($reservation?->fresh()->released_at?->equalTo($releasedAt));
        $this->assertSame(0, $context['pass']->fresh()->reserved_sessions_count);
        $this->assertSame(0, $context['pass']->fresh()->used_sessions_count);

        Carbon::setTestNow();
    }

    public function test_cancellation_without_reservation_and_skip_pass_booking_stays_unlinked(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context();
        $withoutReservation = $this->booking($context, '2026-07-26 10:00:00', issuePass: false);
        $skipPass = $this->booking($context, '2026-07-27 10:00:00', issuePass: false);
        $skipPass->update(['skip_class_pass_reservation' => true]);

        app(CancelClassBooking::class)->execute($withoutReservation);
        app(CancelClassBooking::class)->execute($skipPass);

        $this->assertSame(ClassBookingStatus::Cancelled, $withoutReservation->fresh()->status);
        $this->assertSame(ClassBookingStatus::Cancelled, $skipPass->fresh()->status);
        $this->assertFalse($withoutReservation->classPassReservation()->exists());
        $this->assertFalse($skipPass->classPassReservation()->exists());

        Carbon::setTestNow();
    }

    public function test_cancelled_booking_can_be_restored_or_changed_to_consuming_statuses(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context();
        $booking = $this->booking($context, '2026-07-26 10:00:00');
        app(ReserveCustomerClassPassForBooking::class)->execute($booking);
        app(CancelClassBooking::class)->execute($booking);

        $booking->update(['status' => ClassBookingStatus::Booked->value]);
        app(ReconcileCustomerClassPassForBooking::class)->execute($booking);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $booking->classPassReservation()->firstOrFail()->status);

        $booking->update([
            'status' => ClassBookingStatus::Attended->value,
            'attended_at' => now(),
        ]);
        app(ReconcileCustomerClassPassForBooking::class)->execute($booking);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $booking->classPassReservation()->firstOrFail()->status);

        $booking->update([
            'status' => ClassBookingStatus::Cancelled->value,
            'attended_at' => null,
        ]);
        app(ReconcileCustomerClassPassForBooking::class)->execute($booking);
        $this->assertSame(CustomerClassPassReservationStatus::Released, $booking->classPassReservation()->firstOrFail()->status);

        $booking->update(['status' => ClassBookingStatus::NoShow->value]);
        app(ReconcileCustomerClassPassForBooking::class)->execute($booking);

        $this->assertSame(CustomerClassPassReservationStatus::Used, $booking->classPassReservation()->firstOrFail()->status);
        $this->assertSame(1, $context['pass']->fresh()->used_sessions_count);
        $this->assertSame(0, $context['pass']->fresh()->reserved_sessions_count);

        Carbon::setTestNow();
    }

    public function test_cutoff_boundary_and_customer_locked_preconditions_leave_ledger_unchanged(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context(cancellationCutoffMinutes: 60);
        $booking = $this->booking($context, '2026-07-25 11:00:00');
        $reservation = app(ReserveCustomerClassPassForBooking::class)->execute($booking);

        try {
            app(CancelClassBooking::class)->execute($booking, cutoffErrorKey: 'status');
            $this->fail('Expected cutoff validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(ClassBookingStatus::Booked, $booking->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation?->fresh()->status);
        $this->assertSame(1, $context['pass']->fresh()->reserved_sessions_count);

        Carbon::setTestNow('2026-07-24 10:00:00');
        $booking->update([
            'status' => ClassBookingStatus::Attended->value,
            'attended_at' => now(),
        ]);
        app(ReconcileCustomerClassPassForBooking::class)->execute($booking);

        try {
            app(CancelClassBooking::class)->execute($booking, requireBookedUpcoming: true);
            $this->fail('Expected customer cancellation precondition failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('app.customer_booking_cancel_unavailable'),
                $exception->errors()['booking'][0] ?? null,
            );
        }

        $this->assertSame(ClassBookingStatus::Attended, $booking->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $reservation?->fresh()->status);

        Carbon::setTestNow();
    }

    /**
     * @return array{account: Account, customer: Customer, pass: CustomerClassPass, location: Location, room: Room, classType: ClassType, trainer: Trainer}
     */
    private function context(?int $cancellationCutoffMinutes = null): array
    {
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        $trainer = Trainer::factory()->for($account)->for($trainerType)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => 'group_class',
            'cancellation_cutoff_minutes' => $cancellationCutoffMinutes,
        ]);
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create([
            'sessions_count' => 1,
            'schedule_kind' => 'group_class',
        ]);
        $plan->classTypes()->sync([$classType->id]);
        $plan->trainerTypes()->sync([$trainerType->id]);
        $pass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        return compact('account', 'customer', 'pass', 'location', 'room', 'classType', 'trainer');
    }

    /**
     * @param  array{account: Account, customer: Customer, pass: CustomerClassPass, location: Location, room: Room, classType: ClassType, trainer: Trainer}  $context
     */
    private function booking(
        array $context,
        string $startsAt,
        ClassBookingStatus $status = ClassBookingStatus::Booked,
        bool $issuePass = true,
    ): ClassBooking {
        $startsAt = Carbon::parse($startsAt);
        $scheduledClass = ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($context['room'])
            ->for($context['classType'])
            ->for($context['trainer'])
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);
        $booking = ClassBooking::factory()
            ->for($context['account'])
            ->for($scheduledClass)
            ->for($context['customer'])
            ->create([
                'status' => $status->value,
                'attended_at' => $status === ClassBookingStatus::Attended ? now() : null,
            ]);

        if (! $issuePass) {
            $context['pass']->forceFill([
                'status' => CustomerClassPassStatus::Cancelled->value,
                'is_active' => false,
            ])->save();
        }

        return $booking;
    }
}
