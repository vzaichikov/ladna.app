<?php

namespace Tests\Feature;

use App\Actions\ReconcileCustomerClassPassForBooking;
use App\Actions\RecordManualClassBookingPayment;
use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduleKind;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassBookingCorrection;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClosedClassCorrectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_closed_class_correction_permission_is_critical_and_explicit_for_staff(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $trainer = User::factory()->create(['name' => 'Trainer']);
        $admin = User::factory()->create(['name' => 'Admin']);
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $account->users()->syncWithoutDetaching([
            $trainer->id => ['role' => AccountRole::Trainer->value, 'permissions' => null],
            $admin->id => ['role' => AccountRole::Admin->value, 'permissions' => null],
        ]);

        $this->assertTrue($owner->can('correctClosedClasses', $account));
        $this->assertFalse($trainer->can('correctClosedClasses', $account));
        $this->assertFalse($admin->can('correctClosedClasses', $account));

        $account->memberships()
            ->whereBelongsTo($trainer)
            ->update(['permissions' => [StudioPermission::CorrectClosedClasses->value]]);

        $this->assertTrue($trainer->fresh()->can('correctClosedClasses', $account));

        TrainerType::factory()->for($account)->default()->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.trainers.create', $account))
            ->assertOk()
            ->assertSee(__('app.permission_correct_closed_classes'))
            ->assertSee(__('app.permission_manage_studio_cashflow'))
            ->assertSee(__('app.permission_sensitivity_critical'))
            ->assertSee(__('app.permission_correct_closed_classes_description'))
            ->assertSee(__('app.permission_manage_studio_cashflow_description'));
    }

    public function test_remove_wrong_group_class_customer_can_return_consumed_session(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        [$owner, $account, $scheduledClass, $customer, $customerClassPass, $booking] = $this->closedBookingContext();
        $reservation = $booking->classPassReservation()->firstOrFail();

        $this->assertSame(CustomerClassPassReservationStatus::Used, $reservation->status);
        $this->assertSame(1, $customerClassPass->fresh()->used_sessions_count);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.bookings.corrections.remove', [$account, $booking]), [
                'pass_effect' => ClassBookingCorrection::PassEffectReturnSession,
                'reason' => 'Wrong customer was selected yesterday.',
            ])
            ->assertOk()
            ->assertJsonPath('scheduled_class_id', $scheduledClass->id)
            ->assertJsonPath('message', __('app.closed_class_booking_removed_corrected'));

        $booking->refresh();
        $reservation->refresh();
        $customerClassPass->refresh();

        $this->assertNotNull($booking->corrected_removed_at);
        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation->status);
        $this->assertNull($reservation->used_at);
        $this->assertSame(0, $customerClassPass->used_sessions_count);
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(0, $scheduledClass->visibleClassBookings()->count());

        $correction = ClassBookingCorrection::firstOrFail();
        $this->assertSame(ClassBookingCorrection::ActionRemoved, $correction->action);
        $this->assertSame(ClassBookingCorrection::PassEffectReturnSession, $correction->pass_effect);
        $this->assertSame($customer->id, $correction->old_customer_id);

        Carbon::setTestNow();
    }

    public function test_remove_wrong_private_customer_can_keep_session_consumed(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        [$owner, $account, , , $customerClassPass, $booking] = $this->closedBookingContext(ScheduleKind::PrivateLesson);
        $reservation = $booking->classPassReservation()->firstOrFail();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.bookings.corrections.remove', [$account, $booking]), [
                'pass_effect' => ClassBookingCorrection::PassEffectKeepConsumed,
                'reason' => 'Customer should not be visible, but studio keeps the charged session.',
            ])
            ->assertOk();

        $booking->refresh();
        $reservation->refresh();
        $customerClassPass->refresh();

        $this->assertNotNull($booking->corrected_removed_at);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $reservation->status);
        $this->assertNotNull($reservation->used_at);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertSame(0, $booking->scheduledClass()->firstOrFail()->visibleClassBookings()->count());

        Carbon::setTestNow();
    }

    public function test_add_correct_customer_to_closed_class_consumes_matching_pass(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        [$owner, $account, $scheduledClass] = $this->closedClassContext();
        [$customer, $customerClassPass] = $this->customerWithPass($account, $scheduledClass);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.scheduled-classes.corrections.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $customer->id,
                'status' => ClassBookingStatus::Attended->value,
                'reason' => 'Correct attendee was missing.',
            ])
            ->assertCreated()
            ->assertJsonPath('message', __('app.closed_class_booking_added_corrected'));

        $booking = ClassBooking::whereBelongsTo($scheduledClass, 'scheduledClass')
            ->whereBelongsTo($customer)
            ->firstOrFail();
        $reservation = $booking->classPassReservation()->firstOrFail();

        $this->assertSame(ClassBookingStatus::Attended, $booking->status);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $reservation->status);
        $this->assertSame(1, $customerClassPass->fresh()->used_sessions_count);
        $this->assertSame(ClassBookingCorrection::PassEffectAutoMatched, ClassBookingCorrection::firstOrFail()->pass_effect);

        Carbon::setTestNow();
    }

    public function test_add_correct_customer_without_matching_pass_keeps_no_pass_alert(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        [$owner, $account, $scheduledClass] = $this->closedClassContext();
        $customer = Customer::factory()->for($account)->create(['name' => 'No Pass Client']);

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.scheduled-classes.corrections.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $customer->id,
                'status' => ClassBookingStatus::Attended->value,
                'reason' => 'Correct attendee has no valid pass yet.',
            ])
            ->assertCreated();

        $booking = ClassBooking::whereBelongsTo($scheduledClass, 'scheduledClass')
            ->whereBelongsTo($customer)
            ->firstOrFail();

        $this->assertNull($booking->classPassReservation()->first());
        $this->assertStringContainsString(__('app.no_matching_class_pass_alert'), $response->json('card_html'));
        $this->assertSame(ClassBookingCorrection::PassEffectNoMatchingPass, ClassBookingCorrection::firstOrFail()->pass_effect);

        Carbon::setTestNow();
    }

    public function test_linked_manual_cash_booking_payment_survives_closed_class_correction(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        [$owner, $account, $scheduledClass] = $this->closedClassContext(ScheduleKind::RoomRental);
        $customer = Customer::factory()->for($account)->create(['name' => 'Rental Client']);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create([
                'status' => ClassBookingStatus::Attended->value,
                'attended_at' => $scheduledClass->starts_at,
                'skip_class_pass_reservation' => true,
            ]);
        $payment = app(RecordManualClassBookingPayment::class)->execute($account, $booking, 55000);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.bookings.corrections.remove', [$account, $booking]), [
                'pass_effect' => ClassBookingCorrection::PassEffectKeepConsumed,
                'reason' => 'Rental customer was assigned to the wrong person.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('customer_purchases', [
            'id' => $payment->id,
            'class_booking_id' => $booking->id,
            'amount_cents' => 55000,
        ]);
        $this->assertDatabaseHas('class_booking_corrections', [
            'class_booking_id' => $booking->id,
            'manual_cash_payment_id' => $payment->id,
        ]);

        Carbon::setTestNow();
    }

    /**
     * @return array{0: User, 1: Account, 2: ScheduledClass, 3: Customer, 4: CustomerClassPass, 5: ClassBooking}
     */
    private function closedBookingContext(ScheduleKind $scheduleKind = ScheduleKind::GroupClass): array
    {
        [$owner, $account, $scheduledClass] = $this->closedClassContext($scheduleKind);
        [$customer, $customerClassPass] = $this->customerWithPass($account, $scheduledClass);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create([
                'status' => ClassBookingStatus::Attended->value,
                'attended_at' => $scheduledClass->starts_at,
            ]);

        app(ReconcileCustomerClassPassForBooking::class)->execute($booking);

        return [$owner, $account, $scheduledClass, $customer, $customerClassPass, $booking->refresh()];
    }

    /**
     * @return array{0: User, 1: Account, 2: ScheduledClass}
     */
    private function closedClassContext(ScheduleKind $scheduleKind = ScheduleKind::GroupClass): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => $scheduleKind->value,
            'default_capacity' => $scheduleKind === ScheduleKind::GroupClass ? 10 : 1,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'starts_at' => Carbon::parse('2026-07-03 09:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-07-03 10:00:00', 'UTC'),
                'capacity' => $scheduleKind === ScheduleKind::GroupClass ? 10 : 1,
            ]);

        return [$owner, $account, $scheduledClass->load('classType')];
    }

    /**
     * @return array{0: Customer, 1: CustomerClassPass}
     */
    private function customerWithPass(Account $account, ScheduledClass $scheduledClass): array
    {
        $scheduledClass->loadMissing('classType');
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create([
            'schedule_kind' => $scheduledClass->classType->schedule_kind->value,
            'sessions_count' => 4,
            'price_cents' => 100000,
        ]);
        $plan->classTypes()->attach($scheduledClass->class_type_id);
        $customerClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'price_cents' => $plan->price_cents,
                'sessions_count' => $plan->sessions_count,
                'purchased_at' => Carbon::parse('2026-07-01 10:00:00', 'UTC'),
                'usable_until_at' => Carbon::parse('2026-12-01 10:00:00', 'UTC'),
                'is_paid' => true,
                'paid_amount_cents' => $plan->price_cents,
            ]);

        return [$customer, $customerClassPass];
    }
}
