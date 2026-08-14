<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\ReconcileCustomerClassPassForBooking;
use App\Actions\ReconcileUnreservedCustomerBookingsForIssuedClassPass;
use App\Actions\RecordManualClassBookingPayment;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\User;
use App\Support\Reports\UnpaidClassBookingPaymentsReport;
use App\Support\UnreservedClassPassBookingIssues;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AnytimeRentalClassPassTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_anytime_rental_uses_existing_compatible_pass_when_no_cash_payment_is_entered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00', 'UTC'));
        $context = $this->context();
        $plan = $this->rentalPlan($context, sessions: 1);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $plan,
        );

        $this->actingAs($context['owner'])
            ->post(route('dashboard.accounts.quick-bookings.store', $context['account']), [
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'rental_mode' => 'anytime',
                'location_id' => $context['location']->id,
                'room_id' => $context['room']->id,
                'class_type_id' => $context['classType']->id,
                'starts_at' => '2026-08-15T14:00',
                'ends_at' => '2026-08-15T16:00',
                'customer_id' => $context['customer']->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', __('app.quick_booking_created'));

        $booking = ClassBooking::query()
            ->whereBelongsTo($context['account'])
            ->whereBelongsTo($context['customer'])
            ->firstOrFail();
        $reservation = $booking->classPassReservation()->firstOrFail();

        $this->assertFalse($booking->skip_class_pass_reservation);
        $this->assertSame('anytime', $booking->scheduledClass->metadata['rental_mode']);
        $this->assertSame($customerClassPass->id, $reservation->customer_class_pass_id);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->status);
        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertFalse($booking->manualCashPayment()->exists());
    }

    public function test_issuing_pass_covers_oldest_unpaid_compatible_anytime_rental_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00', 'UTC'));
        $context = $this->context();
        $paidBooking = $this->anytimeRentalBooking($context, '2026-08-10 14:00:00', 120);
        $oldestUnpaidBooking = $this->anytimeRentalBooking($context, '2026-08-11 14:00:00', 120);
        $newerUnpaidBooking = $this->anytimeRentalBooking($context, '2026-08-12 14:00:00', 120);
        $wrongDurationBooking = $this->anytimeRentalBooking($context, '2026-08-13 14:00:00', 90);
        app(RecordManualClassBookingPayment::class)->execute($context['account'], $paidBooking, 70000);
        $plan = $this->rentalPlan($context, sessions: 1);
        $unpaidReport = app(UnpaidClassBookingPaymentsReport::class);

        $this->assertSame(3, $unpaidReport->countForAccount($context['account']));

        $customerClassPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $plan,
        );

        $reservation = $oldestUnpaidBooking->classPassReservation()->firstOrFail();

        $this->assertFalse($oldestUnpaidBooking->fresh()->skip_class_pass_reservation);
        $this->assertSame($customerClassPass->id, $reservation->customer_class_pass_id);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $reservation->status);
        $this->assertTrue($reservation->used_at->equalTo(Carbon::parse('2026-08-11 14:00:00', 'UTC')));

        foreach ([$paidBooking, $newerUnpaidBooking, $wrongDurationBooking] as $uncoveredBooking) {
            $this->assertTrue($uncoveredBooking->fresh()->skip_class_pass_reservation);
            $this->assertFalse($uncoveredBooking->classPassReservation()->exists());
        }

        $customerClassPass->refresh();
        $this->assertSame(CustomerClassPassStatus::UsedUp, $customerClassPass->status);
        $this->assertFalse($customerClassPass->is_active);
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertSame(0, $customerClassPass->remainingSessionsCount());
        $this->assertTrue($paidBooking->manualCashPayment()->exists());
        $this->assertSame(0, app(UnreservedClassPassBookingIssues::class)->countForAccount($context['account']));
        $this->assertSame(2, $unpaidReport->countForAccount($context['account']));

        $reconciliation = app(ReconcileUnreservedCustomerBookingsForIssuedClassPass::class);
        $this->assertFalse($reconciliation->previewForCustomer($context['customer'])['has_changes']);
        $this->assertSame(0, $reconciliation->executeForCustomer($context['customer']));
    }

    public function test_wrong_anytime_duration_stays_uncovered_while_pass_has_an_available_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00', 'UTC'));
        $context = $this->context();
        $plan = $this->rentalPlan($context, sessions: 1);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $plan,
        );
        $wrongDurationBooking = $this->anytimeRentalBooking($context, '2026-08-13 14:00:00', 90);
        $reconciliation = app(ReconcileUnreservedCustomerBookingsForIssuedClassPass::class);

        $this->assertFalse($reconciliation->previewForCustomer($context['customer'])['has_changes']);
        $this->assertSame(0, $reconciliation->executeForCustomer($context['customer']));
        $this->assertTrue($wrongDurationBooking->fresh()->skip_class_pass_reservation);
        $this->assertFalse($wrongDurationBooking->classPassReservation()->exists());
        $this->assertSame(1, $customerClassPass->fresh()->remainingSessionsCount());
    }

    public function test_manual_payment_history_excludes_legacy_rental_even_when_skip_flag_is_false(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00', 'UTC'));
        $context = $this->context();
        $paidBooking = $this->anytimeRentalBooking($context, '2026-08-13 14:00:00', 120);
        $payment = app(RecordManualClassBookingPayment::class)->execute($context['account'], $paidBooking, 70000);
        $paidBooking->update(['skip_class_pass_reservation' => false]);
        $plan = $this->rentalPlan($context, sessions: 1);

        $customerClassPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $plan,
        );

        $this->assertFalse($paidBooking->fresh()->skip_class_pass_reservation);
        $this->assertFalse($paidBooking->classPassReservation()->exists());
        $this->assertSame($payment->id, $paidBooking->manualCashPayment()->firstOrFail()->id);
        $this->assertSame(1, $customerClassPass->fresh()->remainingSessionsCount());
    }

    public function test_later_pass_assignment_blocks_cash_payment_and_releases_on_cancellation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00', 'UTC'));
        $context = $this->context();
        $booking = $this->anytimeRentalBooking($context, '2026-08-15 14:00:00', 120);
        $plan = $this->rentalPlan($context, sessions: 1);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $plan,
        );
        $reservation = $booking->classPassReservation()->firstOrFail();

        $this->assertFalse($booking->fresh()->skip_class_pass_reservation);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->status);

        try {
            app(RecordManualClassBookingPayment::class)->execute($context['account'], $booking, 70000);
            $this->fail('A booking covered by a class pass must reject a direct cash payment.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('app.class_booking_payment_class_pass_reserved'),
                $exception->errors()['amount'][0] ?? null,
            );
        }

        $booking->update(['status' => ClassBookingStatus::Cancelled->value]);
        app(ReconcileCustomerClassPassForBooking::class)->execute($booking->fresh());

        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation->fresh()->status);
        $this->assertSame(CustomerClassPassStatus::Active, $customerClassPass->fresh()->status);
        $this->assertSame(0, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->fresh()->used_sessions_count);
        $this->assertSame(1, $customerClassPass->fresh()->remainingSessionsCount());
        $this->assertFalse($booking->manualCashPayment()->exists());
    }

    /**
     * @return array{owner: User, account: Account, location: Location, room: Room, classType: ClassType, customer: Customer}
     */
    private function context(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'default_currency' => 'UAH',
            'opening_hours' => [
                6 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Rental 120 min',
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 120,
            'default_capacity' => 1,
        ]);
        $customer = Customer::factory()->for($account)->create();

        return compact('owner', 'account', 'location', 'room', 'classType', 'customer');
    }

    /**
     * @param  array{account: Account, room: Room, classType: ClassType}  $context
     */
    private function rentalPlan(array $context, int $sessions): ClassPassPlan
    {
        $plan = ClassPassPlan::factory()->for($context['account'])->create([
            'name' => 'Rental pass',
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'sessions_count' => $sessions,
            'total_validity_days' => 180,
        ]);
        $plan->classTypes()->sync([$context['classType']->id]);
        $plan->rooms()->sync([$context['room']->id]);

        return $plan;
    }

    /**
     * @param  array{account: Account, location: Location, room: Room, classType: ClassType, customer: Customer}  $context
     */
    private function anytimeRentalBooking(array $context, string $startsAt, int $durationMinutes): ClassBooking
    {
        $startsAt = Carbon::parse($startsAt, 'UTC');
        $scheduledClass = ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($context['room'])
            ->for($context['classType'])
            ->create([
                'trainer_id' => null,
                'title' => $context['classType']->name,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($durationMinutes),
                'metadata' => [
                    'source' => 'quick_booking',
                    'schedule_kind' => ScheduleKind::RoomRental->value,
                    'rental_mode' => 'anytime',
                    'skip_class_pass_reservation' => true,
                ],
            ]);

        return ClassBooking::factory()
            ->for($context['account'])
            ->for($scheduledClass)
            ->for($context['customer'])
            ->create(['skip_class_pass_reservation' => true]);
    }
}
