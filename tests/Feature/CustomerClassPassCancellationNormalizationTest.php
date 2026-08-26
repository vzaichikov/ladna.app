<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\NormalizeCustomerClassPasses;
use App\Actions\ReconcileUnreservedCustomerBookingsForIssuedClassPass;
use App\Actions\ReserveCustomerClassPassForBooking;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
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
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerClassPassCancellationNormalizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_customer_cancellation_is_not_restored_by_preview_or_apply(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context(sessions: 2);
        $booking = $this->booking($context, '2026-07-26 10:00:00');
        $reservation = app(ReserveCustomerClassPassForBooking::class)->execute($booking);

        $this->actingAs($context['customer'], 'customer')
            ->patch(route('customer.bookings.cancel', [$context['account']->slug, $booking]))
            ->assertRedirect(route('customer.dashboard', $context['account']->slug))
            ->assertSessionHas('status', __('app.customer_booking_cancelled'));

        $this->actingAs($context['customer'], 'customer')
            ->patch(route('customer.bookings.cancel', [$context['account']->slug, $booking]))
            ->assertRedirect(route('customer.dashboard', $context['account']->slug));

        $normalization = app(ReconcileUnreservedCustomerBookingsForIssuedClassPass::class);
        $preview = $normalization->previewForCustomer($context['customer']);

        $this->assertSame(ClassBookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation?->fresh()->status);
        $this->assertSame(['reserved' => 0, 'used' => 0, 'released' => 0], $preview['totals']);
        $this->assertFalse($preview['has_changes']);

        auth('customer')->logout();

        $this->actingAs($context['owner'], 'web')
            ->post(route('dashboard.accounts.customers.class-passes.backfill', [
                $context['account'],
                $context['customer'],
            ]))
            ->assertRedirect(route('dashboard.accounts.customers.edit', [
                $context['account'],
                $context['customer'],
            ]));

        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation?->fresh()->status);
        $this->assertSame(0, $context['pass']->fresh()->reserved_sessions_count);
        $this->assertSame(0, $context['pass']->fresh()->used_sessions_count);
        $this->assertSame(2, $context['pass']->fresh()->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_manual_normalization_repairs_legacy_cancellations_and_reconciles_mixed_bookings_once(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context(sessions: 10);
        $legacyReserved = $this->booking($context, '2026-07-30 10:00:00', ClassBookingStatus::Cancelled);
        $legacyUsed = $this->booking($context, '2026-07-22 10:00:00', ClassBookingStatus::Cancelled);
        $booked = $this->booking($context, '2026-07-30 12:00:00');
        $attended = $this->booking($context, '2026-07-23 10:00:00', ClassBookingStatus::Attended);
        $noShow = $this->booking($context, '2026-07-24 10:00:00', ClassBookingStatus::NoShow);

        $legacyReservedReservation = $this->reservation(
            $context['pass'],
            $legacyReserved,
            CustomerClassPassReservationStatus::Reserved,
        );
        $legacyUsedReservation = $this->reservation(
            $context['pass'],
            $legacyUsed,
            CustomerClassPassReservationStatus::Used,
        );

        $skipBooking = $this->booking($context, '2026-07-21 10:00:00', ClassBookingStatus::Cancelled);
        $skipBooking->update(['skip_class_pass_reservation' => true]);
        $skipReservation = $this->reservation(
            $context['pass'],
            $skipBooking,
            CustomerClassPassReservationStatus::Used,
        );

        $correctedBooking = $this->booking($context, '2026-07-20 10:00:00', ClassBookingStatus::Cancelled);
        $correctedBooking->update(['corrected_removed_at' => now()]);
        $correctedReservation = $this->reservation(
            $context['pass'],
            $correctedBooking,
            CustomerClassPassReservationStatus::Used,
        );

        $studioCancelledBooking = $this->booking($context, '2026-07-19 10:00:00', ClassBookingStatus::Cancelled);
        $studioCancelledBooking->scheduledClass->update(['status' => ScheduledClassStatus::Cancelled->value]);
        $studioCancelledReservation = $this->reservation(
            $context['pass'],
            $studioCancelledBooking,
            CustomerClassPassReservationStatus::Used,
        );
        $studioReturnedBooking = $this->booking($context, '2026-07-18 10:00:00', ClassBookingStatus::Cancelled);
        $studioReturnedBooking->scheduledClass->update(['status' => ScheduledClassStatus::Cancelled->value]);
        $studioReturnedReservation = $this->reservation(
            $context['pass'],
            $studioReturnedBooking,
            CustomerClassPassReservationStatus::Released,
        );

        $context['pass']->forceFill([
            'reserved_sessions_count' => 1,
            'used_sessions_count' => 4,
            'opened_at' => Carbon::parse('2026-07-19 10:00:00'),
            'expires_at' => Carbon::parse('2026-08-18 10:00:00'),
        ])->save();

        $normalization = app(ReconcileUnreservedCustomerBookingsForIssuedClassPass::class);
        $preview = $normalization->previewForCustomer($context['customer']);

        $this->assertSame(['reserved' => 1, 'used' => 1, 'released' => 2], $preview['totals']);
        $this->assertTrue($preview['has_changes']);

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.customers.edit', [
                $context['account'],
                $context['customer'],
                'class_pass_backfill_preview' => 1,
            ]))
            ->assertOk()
            ->assertSee(__('app.released').': 2');

        $this->actingAs($context['owner'])
            ->post(route('dashboard.accounts.customers.class-passes.backfill', [
                $context['account'],
                $context['customer'],
            ]))
            ->assertRedirect();

        $this->assertSame(CustomerClassPassReservationStatus::Released, $legacyReservedReservation->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Released, $legacyUsedReservation->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $booked->classPassReservation()->firstOrFail()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $attended->classPassReservation()->firstOrFail()->status);
        $this->assertNull($noShow->classPassReservation()->first());
        $this->assertSame(CustomerClassPassReservationStatus::Used, $skipReservation->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $correctedReservation->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $studioCancelledReservation->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Released, $studioReturnedReservation->fresh()->status);

        $customerClassPass = $context['pass']->fresh();
        $this->assertSame(1, $customerClassPass->reserved_sessions_count);
        $this->assertSame(4, $customerClassPass->used_sessions_count);
        $this->assertSame(5, $customerClassPass->remainingSessionsCount());
        $this->assertTrue($customerClassPass->opened_at->equalTo(Carbon::parse('2026-07-19 10:00:00')));
        $this->assertTrue($customerClassPass->expires_at->equalTo(Carbon::parse('2026-08-18 10:00:00')));

        $secondPreview = $normalization->previewForCustomer($context['customer']->fresh());
        $this->assertSame(['reserved' => 0, 'used' => 0, 'released' => 0], $secondPreview['totals']);
        $this->assertFalse($secondPreview['has_changes']);
        $this->assertSame(0, $normalization->executeForCustomer(
            $context['customer']->fresh(),
            repairCancelledReservations: true,
        ));

        Carbon::setTestNow();
    }

    public function test_manual_normalization_reopens_historical_used_up_pass_after_legacy_release(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context(sessions: 1);
        $booking = $this->booking($context, '2026-07-24 10:00:00', ClassBookingStatus::Cancelled);
        $reservation = $this->reservation(
            $context['pass'],
            $booking,
            CustomerClassPassReservationStatus::Used,
        );
        $context['pass']->forceFill([
            'status' => CustomerClassPassStatus::UsedUp->value,
            'is_active' => false,
            'reserved_sessions_count' => 0,
            'used_sessions_count' => 1,
            'opened_at' => Carbon::parse('2026-07-24 10:00:00'),
            'expires_at' => Carbon::parse('2026-08-23 10:00:00'),
            'closed_at' => Carbon::parse('2026-07-24 11:00:00'),
        ])->save();

        $normalization = app(ReconcileUnreservedCustomerBookingsForIssuedClassPass::class);
        $preview = $normalization->previewForCustomer($context['customer']);

        $this->assertSame(1, $preview['totals']['released']);
        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.customers.edit', [
                $context['account'],
                $context['customer'],
            ]))
            ->assertOk()
            ->assertSee(__('app.preview_class_pass_backfill'));

        $normalization->executeForCustomer(
            $context['customer'],
            repairCancelledReservations: true,
        );

        $customerClassPass = $context['pass']->fresh();
        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation->fresh()->status);
        $this->assertSame(CustomerClassPassStatus::Active, $customerClassPass->status);
        $this->assertTrue($customerClassPass->is_active);
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->used_sessions_count);
        $this->assertSame(1, $customerClassPass->remainingSessionsCount());
        $this->assertNull($customerClassPass->opened_at);
        $this->assertNull($customerClassPass->expires_at);
        $this->assertNull($customerClassPass->closed_at);

        Carbon::setTestNow();
    }

    public function test_issuing_new_pass_does_not_implicitly_repair_legacy_cancellation(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context(sessions: 1);
        $booking = $this->booking($context, '2026-07-24 10:00:00', ClassBookingStatus::Cancelled);
        $reservation = $this->reservation(
            $context['pass'],
            $booking,
            CustomerClassPassReservationStatus::Used,
        );
        $context['pass']->forceFill([
            'status' => CustomerClassPassStatus::UsedUp->value,
            'is_active' => false,
            'used_sessions_count' => 1,
            'opened_at' => Carbon::parse('2026-07-24 10:00:00'),
            'expires_at' => Carbon::parse('2026-08-23 10:00:00'),
            'closed_at' => Carbon::parse('2026-07-24 11:00:00'),
        ])->save();

        $newPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $context['plan'],
        );

        $this->assertNotSame($context['pass']->id, $newPass->id);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $reservation->fresh()->status);
        $this->assertSame(CustomerClassPassStatus::UsedUp, $context['pass']->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_scheduled_normalization_does_not_consume_cancelled_reserved_booking(): void
    {
        Carbon::setTestNow('2026-07-25 10:00:00');
        $context = $this->context(sessions: 2);
        $booking = $this->booking($context, '2026-07-24 10:00:00', ClassBookingStatus::Cancelled);
        $reservation = $this->reservation(
            $context['pass'],
            $booking,
            CustomerClassPassReservationStatus::Reserved,
        );
        $context['pass']->forceFill(['reserved_sessions_count' => 1])->save();

        app(NormalizeCustomerClassPasses::class)->forPass($context['pass']->fresh());
        app(NormalizeCustomerClassPasses::class)->forPass($context['pass']->fresh());

        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->fresh()->status);
        $this->assertSame(1, $context['pass']->fresh()->reserved_sessions_count);
        $this->assertSame(0, $context['pass']->fresh()->used_sessions_count);

        Carbon::setTestNow();
    }

    /**
     * @return array{owner: User, account: Account, customer: Customer, plan: ClassPassPlan, pass: CustomerClassPass, location: Location, room: Room, classType: ClassType, trainer: Trainer}
     */
    private function context(int $sessions): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'slug' => 'cancel-normalization-'.fake()->unique()->numberBetween(10000, 99999),
            'timezone' => 'UTC',
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        $trainer = Trainer::factory()->for($account)->for($trainerType)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => 'group_class',
            'cancellation_cutoff_minutes' => 60,
        ]);
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create([
            'sessions_count' => $sessions,
            'schedule_kind' => 'group_class',
        ]);
        $plan->classTypes()->sync([$classType->id]);
        $plan->trainerTypes()->sync([$trainerType->id]);
        $pass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        return compact('owner', 'account', 'customer', 'plan', 'pass', 'location', 'room', 'classType', 'trainer');
    }

    /**
     * @param  array{account: Account, customer: Customer, location: Location, room: Room, classType: ClassType, trainer: Trainer}  $context
     */
    private function booking(
        array $context,
        string $startsAt,
        ClassBookingStatus $status = ClassBookingStatus::Booked,
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

        return ClassBooking::factory()
            ->for($context['account'])
            ->for($scheduledClass)
            ->for($context['customer'])
            ->create([
                'status' => $status->value,
                'attended_at' => $status === ClassBookingStatus::Attended ? $startsAt : null,
            ]);
    }

    private function reservation(
        CustomerClassPass $customerClassPass,
        ClassBooking $booking,
        CustomerClassPassReservationStatus $status,
    ): CustomerClassPassReservation {
        return CustomerClassPassReservation::factory()
            ->for($customerClassPass->account)
            ->for($customerClassPass)
            ->for($booking)
            ->for($booking->scheduledClass)
            ->create([
                'status' => $status->value,
                'reserved_at' => $booking->scheduledClass->starts_at->copy()->subDay(),
                'used_at' => $status === CustomerClassPassReservationStatus::Used
                    ? $booking->scheduledClass->starts_at
                    : null,
                'released_at' => $status === CustomerClassPassReservationStatus::Released ? now() : null,
            ]);
    }
}
