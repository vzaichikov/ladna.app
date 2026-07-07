<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\NormalizeCustomerClassPasses;
use App\Actions\ReconcileCustomerClassPassForBooking;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
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

class CustomerClassPassBusinessFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bookings_reserve_available_sessions_and_show_alert_when_overbooked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 1);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
        $firstClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $secondClass = $this->scheduledClass($context, '2026-06-22 10:00:00');

        $firstResponse = $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $firstClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $this->assertStringContainsString($customerClassPass->code, $firstResponse->json('card_html'));

        $firstBooking = $firstClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail();

        $this->assertSame($context['owner']->id, $firstBooking->booked_by_actor_user_id);
        $this->assertSame($context['owner']->name, $firstBooking->booked_by_actor_name);
        $this->assertSame('owner', $firstBooking->booked_by_actor_role);
        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);

        $secondResponse = $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $secondClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $this->assertStringContainsString(__('app.no_matching_class_pass_alert'), $secondResponse->json('card_html'));

        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(1, CustomerClassPassReservation::where('customer_class_pass_id', $customerClassPass->id)->count());

        Carbon::setTestNow();
    }

    public function test_issuing_pass_reconciles_all_existing_unlinked_customer_bookings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:30:00'));
        $context = $this->context();
        $pastClass = $this->scheduledClass($context, '2026-06-20 10:00:00');
        $currentClass = $this->scheduledClass($context, '2026-06-20 12:00:00');
        $futureClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $pastBooking = $this->unlinkedBooking($context, $pastClass);
        $currentBooking = $this->unlinkedBooking($context, $currentClass);
        $futureBooking = $this->unlinkedBooking($context, $futureClass);
        $plan = $this->plan($context, sessions: 3);

        $this->assertFalse($pastBooking->classPassReservation()->exists());
        $this->assertFalse($currentBooking->classPassReservation()->exists());
        $this->assertFalse($futureBooking->classPassReservation()->exists());

        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);

        $pastReservation = $pastBooking->classPassReservation()->firstOrFail();
        $currentReservation = $currentBooking->classPassReservation()->firstOrFail();
        $futureReservation = $futureBooking->classPassReservation()->firstOrFail();
        $customerClassPass->refresh();

        $this->assertSame('booked', $pastBooking->fresh()->status->value);
        $this->assertSame('booked', $currentBooking->fresh()->status->value);
        $this->assertSame('booked', $futureBooking->fresh()->status->value);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $pastReservation->status);
        $this->assertTrue($pastReservation->used_at->equalTo(Carbon::parse('2026-06-20 10:00:00')));
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $currentReservation->status);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $futureReservation->status);
        $this->assertSame(2, $customerClassPass->reserved_sessions_count);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertSame(0, $customerClassPass->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_online_pass_reconciles_unreserved_bookings_before_and_after_purchase_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 10:00:00'));
        $context = $this->context();
        $pastClass = $this->scheduledClass($context, '2026-07-06 18:00:00');
        $futureClass = $this->scheduledClass($context, '2026-07-08 18:00:00');
        $pastBooking = $this->unlinkedBooking($context, $pastClass, 'attended');
        $futureBooking = $this->unlinkedBooking($context, $futureClass);
        $plan = $this->plan($context, sessions: 2);

        $customerClassPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $plan,
            source: 'online_payment',
            purchasedAt: Carbon::parse('2026-07-07 10:00:00'),
        );

        $pastReservation = $pastBooking->classPassReservation()->firstOrFail();
        $futureReservation = $futureBooking->classPassReservation()->firstOrFail();
        $customerClassPass->refresh();

        $this->assertSame($customerClassPass->id, $pastReservation->customer_class_pass_id);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $pastReservation->status);
        $this->assertTrue($pastReservation->used_at->equalTo(Carbon::parse('2026-07-06 18:00:00')));
        $this->assertSame($customerClassPass->id, $futureReservation->customer_class_pass_id);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $futureReservation->status);
        $this->assertSame(1, $customerClassPass->reserved_sessions_count);
        $this->assertSame(1, $customerClassPass->used_sessions_count);

        Carbon::setTestNow();
    }

    public function test_new_online_pass_does_not_duplicate_existing_active_booking_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 1);
        $oldPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $plan,
            purchasedAt: Carbon::parse('2026-07-01 10:00:00'),
        );
        $scheduledClass = $this->scheduledClass($context, '2026-07-08 18:00:00');
        $booking = $this->unlinkedBooking($context, $scheduledClass);

        app(ReconcileCustomerClassPassForBooking::class)->execute($booking);

        $oldReservation = $booking->classPassReservation()->firstOrFail();
        $this->assertSame($oldPass->id, $oldReservation->customer_class_pass_id);

        $newPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $plan,
            source: 'online_payment',
            purchasedAt: Carbon::parse('2026-07-07 10:00:00'),
        );

        $this->assertSame(1, $booking->classPassReservation()->count());
        $this->assertSame($oldPass->id, $booking->classPassReservation()->firstOrFail()->customer_class_pass_id);
        $this->assertSame(1, $oldPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, $newPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, $newPass->fresh()->used_sessions_count);

        Carbon::setTestNow();
    }

    public function test_new_pass_rebalances_customer_ledger_chronologically_after_old_pass_future_reservations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 4);
        $oldPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $plan,
            purchasedAt: Carbon::parse('2026-07-01 10:00:00'),
        );
        $usedClass1 = $this->scheduledClass($context, '2026-07-03 10:00:00');
        $usedClass2 = $this->scheduledClass($context, '2026-07-04 10:00:00');
        $missingPastClass = $this->scheduledClass($context, '2026-07-05 10:00:00');
        $futureClass1 = $this->scheduledClass($context, '2026-07-08 10:00:00');
        $futureClass2 = $this->scheduledClass($context, '2026-07-09 10:00:00');

        foreach ([
            $this->unlinkedBooking($context, $usedClass1, 'attended'),
            $this->unlinkedBooking($context, $usedClass2, 'attended'),
            $this->unlinkedBooking($context, $futureClass1),
            $this->unlinkedBooking($context, $futureClass2),
        ] as $booking) {
            app(ReconcileCustomerClassPassForBooking::class)->execute($booking);
        }

        $missingPastBooking = $this->unlinkedBooking($context, $missingPastClass, 'attended');
        app(ReconcileCustomerClassPassForBooking::class)->execute($missingPastBooking);

        $this->assertFalse($missingPastBooking->classPassReservation()->exists());
        $this->assertSame(2, $oldPass->fresh()->used_sessions_count);
        $this->assertSame(2, $oldPass->fresh()->reserved_sessions_count);

        $newPass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $plan,
            source: 'online_payment',
            purchasedAt: Carbon::parse('2026-07-07 10:00:00'),
        );

        $oldPass->refresh();
        $newPass->refresh();

        $this->assertSame($oldPass->id, $missingPastBooking->classPassReservation()->firstOrFail()->customer_class_pass_id);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $missingPastBooking->classPassReservation()->firstOrFail()->status);
        $this->assertSame($oldPass->id, $futureClass1->classBookings()->firstOrFail()->classPassReservation()->firstOrFail()->customer_class_pass_id);
        $this->assertSame($newPass->id, $futureClass2->classBookings()->firstOrFail()->classPassReservation()->firstOrFail()->customer_class_pass_id);
        $this->assertSame(3, $oldPass->used_sessions_count);
        $this->assertSame(1, $oldPass->reserved_sessions_count);
        $this->assertSame(0, $newPass->used_sessions_count);
        $this->assertSame(1, $newPass->reserved_sessions_count);

        Carbon::setTestNow();
    }

    public function test_issuing_non_matching_pass_does_not_reconcile_unlinked_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $context = $this->context();
        $otherClassType = ClassType::factory()->for($context['account'])->create(['schedule_kind' => 'group_class']);
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $booking = $this->unlinkedBooking($context, $scheduledClass);
        $plan = $this->plan($context, sessions: 1, classType: $otherClassType);

        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);

        $this->assertFalse($booking->classPassReservation()->exists());
        $this->assertSame(0, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->fresh()->used_sessions_count);
        $this->assertSame(1, $customerClassPass->fresh()->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_issuing_pass_does_not_reconcile_cancelled_scheduled_class_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $context = $this->context();
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $scheduledClass->forceFill([
            'status' => ScheduledClassStatus::Cancelled->value,
            'is_manually_modified' => true,
        ])->save();
        $booking = $this->unlinkedBooking($context, $scheduledClass);
        $plan = $this->plan($context, sessions: 1);

        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);

        $this->assertFalse($booking->classPassReservation()->exists());
        $this->assertSame(0, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->fresh()->used_sessions_count);

        Carbon::setTestNow();
    }

    public function test_attendance_uses_reserved_session_and_reverting_to_booked_restores_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 2);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');

        $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
            'customer_id' => $context['customer']->id,
        ]);

        $booking = $scheduledClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail();

        $this->actingAs($context['owner'])
            ->patch(route('dashboard.accounts.bookings.update', [$context['account'], $booking]), ['status' => 'attended'])
            ->assertRedirect();

        $customerClassPass->refresh();
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertNotNull($customerClassPass->opened_at);
        $this->assertNotNull($customerClassPass->expires_at);

        $this->actingAs($context['owner'])
            ->patch(route('dashboard.accounts.bookings.update', [$context['account'], $booking]), ['status' => 'booked'])
            ->assertRedirect();

        $customerClassPass->refresh();
        $reservation = $booking->classPassReservation()->firstOrFail();
        $this->assertSame(1, $customerClassPass->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->used_sessions_count);
        $this->assertNull($customerClassPass->opened_at);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->status);
        $this->assertSame(1, $customerClassPass->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_cancelled_and_no_show_bookings_consume_sessions_and_normalizer_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));

        $context = $this->context();
        $plan = $this->plan($context, sessions: 3);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
        $bookedClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $cancelledClass = $this->scheduledClass($context, '2026-06-22 10:00:00');
        $noShowClass = $this->scheduledClass($context, '2026-06-23 10:00:00');

        foreach ([$bookedClass, $cancelledClass, $noShowClass] as $scheduledClass) {
            $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
                'customer_id' => $context['customer']->id,
            ]);
        }

        $cancelledBooking = $cancelledClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail();
        $noShowBooking = $noShowClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail();

        $this->actingAs($context['owner'])
            ->patch(route('dashboard.accounts.bookings.update', [$context['account'], $cancelledBooking]), ['status' => 'cancelled'])
            ->assertRedirect();
        $this->actingAs($context['owner'])
            ->patch(route('dashboard.accounts.bookings.update', [$context['account'], $noShowBooking]), ['status' => 'no_show'])
            ->assertRedirect();

        app(NormalizeCustomerClassPasses::class)->execute();
        app(NormalizeCustomerClassPasses::class)->execute();

        $customerClassPass->refresh();
        $this->assertSame(1, $customerClassPass->reserved_sessions_count);
        $this->assertSame(2, $customerClassPass->used_sessions_count);
        $this->assertSame(0, $customerClassPass->remainingSessionsCount());
        $this->assertSame(CustomerClassPassReservationStatus::Used, $cancelledBooking->classPassReservation()->firstOrFail()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $noShowBooking->classPassReservation()->firstOrFail()->status);

        Carbon::setTestNow();
    }

    public function test_normalizer_consumes_reserved_session_after_class_has_ended_without_changing_booking_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 10:00:00'));

        $context = $this->context();
        $plan = $this->plan($context, sessions: 1);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
        $scheduledClass = $this->scheduledClass($context, '2026-06-25 10:00:00');

        $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $booking = $scheduledClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail();
        $reservation = $booking->classPassReservation()->firstOrFail();

        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->status);
        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);

        app(NormalizeCustomerClassPasses::class)->execute();
        app(NormalizeCustomerClassPasses::class)->execute();

        $customerClassPass->refresh();
        $reservation->refresh();
        $booking->refresh();

        $this->assertSame('booked', $booking->status->value);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $reservation->status);
        $this->assertTrue($reservation->used_at->equalTo(Carbon::parse('2026-06-25 10:00:00')));
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertSame(CustomerClassPassStatus::UsedUp, $customerClassPass->status);
        $this->assertFalse($customerClassPass->is_active);
        $this->assertTrue($customerClassPass->closed_at->equalTo(Carbon::parse('2026-06-26 10:00:00')));

        Carbon::setTestNow();
    }

    public function test_normalizer_waits_until_studio_cancellation_window_closes_before_consuming_reserved_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 11:59:00'));

        $context = $this->context();
        $plan = $this->plan($context, sessions: 1);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
        $scheduledClass = $this->scheduledClass($context, '2026-06-25 10:00:00');

        $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $reservation = $scheduledClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail()->classPassReservation()->firstOrFail();

        app(NormalizeCustomerClassPasses::class)->execute();

        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->fresh()->status);
        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->fresh()->used_sessions_count);

        Carbon::setTestNow(Carbon::parse('2026-06-25 12:01:00'));

        app(NormalizeCustomerClassPasses::class)->execute();

        $this->assertSame(CustomerClassPassReservationStatus::Used, $reservation->fresh()->status);
        $this->assertSame(0, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(1, $customerClassPass->fresh()->used_sessions_count);

        Carbon::setTestNow();
    }

    public function test_normalizer_does_not_consume_cancelled_scheduled_class_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 10:00:00'));

        $context = $this->context();
        $plan = $this->plan($context, sessions: 1);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
        $scheduledClass = $this->scheduledClass($context, '2026-06-25 10:00:00');

        $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $reservation = $scheduledClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail()->classPassReservation()->firstOrFail();

        $scheduledClass->forceFill([
            'status' => ScheduledClassStatus::Cancelled->value,
            'is_manually_modified' => true,
        ])->save();

        app(NormalizeCustomerClassPasses::class)->execute();

        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->fresh()->status);
        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->fresh()->used_sessions_count);

        Carbon::setTestNow();
    }

    public function test_admin_delete_before_cutoff_releases_reserved_pass_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));

        $context = $this->context();
        $context['classType']->update(['cancellation_cutoff_minutes' => 60]);
        $plan = $this->plan($context, sessions: 1);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');

        $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $booking = $scheduledClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail();
        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->fresh()->remainingSessionsCount());

        $this->actingAs($context['owner'])
            ->deleteJson(route('dashboard.accounts.bookings.destroy', [$context['account'], $booking]))
            ->assertOk();

        $customerClassPass->refresh();
        $this->assertModelMissing($booking);
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->used_sessions_count);
        $this->assertSame(1, $customerClassPass->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_oldest_matching_pass_is_reserved_first(): void
    {
        $context = $this->context();
        $plan = $this->plan($context, sessions: 1);
        $oldPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan, purchasedAt: Carbon::parse('2026-06-01'));
        $newPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan, purchasedAt: Carbon::parse('2026-06-10'));
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');

        $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
            'customer_id' => $context['customer']->id,
        ]);

        $this->assertSame(1, $oldPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, $newPass->fresh()->reserved_sessions_count);
    }

    public function test_pass_cannot_reserve_class_after_total_validity_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 1, totalValidityDays: 5);
        $customerClassPass = app(IssueCustomerClassPass::class)
            ->execute($context['account'], $context['customer'], $plan, purchasedAt: Carbon::parse('2026-06-01 10:00:00'));
        $scheduledClass = $this->scheduledClass($context, '2026-06-06 10:00:00');

        $response = $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $this->assertStringContainsString(__('app.no_matching_class_pass_alert'), $response->json('card_html'));
        $this->assertSame(0, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, CustomerClassPassReservation::where('customer_class_pass_id', $customerClassPass->id)->count());

        Carbon::setTestNow();
    }

    public function test_frozen_pass_is_not_reserved_for_new_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 1);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
        $customerClassPass->forceFill([
            'status' => CustomerClassPassStatus::Freezed->value,
            'is_active' => true,
            'frozen_at' => Carbon::parse('2026-06-20 10:00:00'),
        ])->save();
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');

        $response = $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $booking = $scheduledClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail();

        $this->assertStringContainsString(__('app.no_matching_class_pass_alert'), $response->json('card_html'));
        $this->assertFalse($booking->classPassReservation()->exists());
        $this->assertSame(0, $customerClassPass->fresh()->reserved_sessions_count);

        Carbon::setTestNow();
    }

    public function test_private_lessons_and_rentals_match_trainer_type_and_room(): void
    {
        $context = $this->context();
        $privateType = ClassType::factory()->for($context['account'])->create(['schedule_kind' => 'private_lesson']);
        $rentalType = ClassType::factory()->for($context['account'])->create(['schedule_kind' => 'room_rental']);
        $topTrainerType = TrainerType::factory()->for($context['account'])->create(['name' => 'Top']);
        $topTrainer = Trainer::factory()->for($context['account'])->for($topTrainerType)->create();
        $otherRoom = Room::factory()->for($context['account'])->for($context['location'])->create(['name' => 'Other room']);
        $privatePlan = $this->plan($context, sessions: 1, classType: $privateType, trainerType: $topTrainerType);
        $rentalPlan = $this->plan($context, sessions: 1, classType: $rentalType, trainerType: null, room: $otherRoom);
        $privatePass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $privatePlan);
        $rentalPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $rentalPlan);
        $privateClass = $this->scheduledClass($context, '2026-06-21 10:00:00', $privateType, $topTrainer);
        $rentalClassWrongRoom = $this->scheduledClass($context, '2026-06-22 10:00:00', $rentalType, null, $context['room']);
        $rentalClassRightRoom = $this->scheduledClass($context, '2026-06-23 10:00:00', $rentalType, null, $otherRoom);

        $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $privateClass]), [
            'customer_id' => $context['customer']->id,
        ]);
        $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $rentalClassWrongRoom]), [
            'customer_id' => $context['customer']->id,
        ]);
        $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $rentalClassRightRoom]), [
            'customer_id' => $context['customer']->id,
        ]);

        $this->assertSame(1, $privatePass->fresh()->reserved_sessions_count);
        $this->assertSame(1, $rentalPass->fresh()->reserved_sessions_count);
        $this->assertNull($rentalClassWrongRoom->classBookings()->firstOrFail()->classPassReservation);
    }

    public function test_normalizer_closes_expired_and_used_up_passes(): void
    {
        $context = $this->context();
        $expiredPlan = $this->plan($context, sessions: 4);
        $usedUpPlan = $this->plan($context, sessions: 1);
        $expiredPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $expiredPlan);
        $usedUpPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $usedUpPlan);

        $expiredBooking = $this->bookingWithUsedReservation($context, $expiredPass, '2026-05-01 10:00:00');
        $usedUpBooking = $this->bookingWithUsedReservation($context, $usedUpPass, '2026-06-19 10:00:00');

        app(NormalizeCustomerClassPasses::class)->execute();

        $this->assertSame('expired', $expiredPass->fresh()->status->value);
        $this->assertFalse($expiredPass->fresh()->is_active);
        $this->assertSame('used_up', $usedUpPass->fresh()->status->value);
        $this->assertFalse($usedUpPass->fresh()->is_active);
        $this->assertNotNull($expiredBooking->fresh());
        $this->assertNotNull($usedUpBooking->fresh());
    }

    public function test_normalizer_expires_unopened_pass_after_total_validity_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 4, totalValidityDays: 10);
        $customerClassPass = app(IssueCustomerClassPass::class)
            ->execute($context['account'], $context['customer'], $plan, purchasedAt: Carbon::parse('2026-06-01 10:00:00'));

        app(NormalizeCustomerClassPasses::class)->execute();

        $customerClassPass->refresh();
        $this->assertSame(CustomerClassPassStatus::Expired, $customerClassPass->status);
        $this->assertFalse($customerClassPass->is_active);
        $this->assertNull($customerClassPass->opened_at);
        $this->assertTrue($customerClassPass->usable_until_at->equalTo(Carbon::parse('2026-06-11 10:00:00')));
        $this->assertNotNull($customerClassPass->closed_at);

        Carbon::setTestNow();
    }

    public function test_normalizer_consumes_elapsed_reservations_before_expiring_pass_after_total_validity_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 4, totalValidityDays: 10);
        $customerClassPass = app(IssueCustomerClassPass::class)
            ->execute($context['account'], $context['customer'], $plan, purchasedAt: Carbon::parse('2026-06-01 10:00:00'));
        $scheduledClass = $this->scheduledClass($context, '2026-06-05 10:00:00');

        $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $booking = $scheduledClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail();
        $reservation = $booking->classPassReservation()->firstOrFail();
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->status);

        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        app(NormalizeCustomerClassPasses::class)->execute();

        $customerClassPass->refresh();
        $reservation->refresh();
        $this->assertSame(CustomerClassPassStatus::Expired, $customerClassPass->status);
        $this->assertFalse($customerClassPass->is_active);
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $reservation->status);
        $this->assertTrue($reservation->used_at->equalTo(Carbon::parse('2026-06-05 10:00:00')));
        $this->assertNull($reservation->released_at);
        $this->assertNotNull($booking->fresh());

        Carbon::setTestNow();
    }

    public function test_normalizer_does_not_reactivate_manually_cancelled_pass(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 4);
        $customerClassPass = app(IssueCustomerClassPass::class)
            ->execute($context['account'], $context['customer'], $plan, purchasedAt: Carbon::parse('2026-06-19 10:00:00'));
        $closedAt = Carbon::parse('2026-06-19 12:00:00');
        $customerClassPass->update([
            'status' => CustomerClassPassStatus::Cancelled->value,
            'is_active' => false,
            'closed_at' => $closedAt,
        ]);

        app(NormalizeCustomerClassPasses::class)->execute();

        $customerClassPass->refresh();
        $this->assertSame(CustomerClassPassStatus::Cancelled, $customerClassPass->status);
        $this->assertFalse($customerClassPass->is_active);
        $this->assertTrue($customerClassPass->closed_at->equalTo($closedAt));

        Carbon::setTestNow();
    }

    public function test_normalizer_closes_inactive_pass_left_with_active_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 4);
        $customerClassPass = app(IssueCustomerClassPass::class)
            ->execute($context['account'], $context['customer'], $plan, purchasedAt: Carbon::parse('2026-06-19 10:00:00'));
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $booking = ClassBooking::factory()
            ->for($context['account'])
            ->for($scheduledClass)
            ->for($context['customer'])
            ->create();
        $reservation = $customerClassPass->reservations()->create([
            'account_id' => $context['account']->id,
            'class_booking_id' => $booking->id,
            'scheduled_class_id' => $scheduledClass->id,
            'status' => CustomerClassPassReservationStatus::Reserved->value,
            'reserved_at' => Carbon::parse('2026-06-19 11:00:00'),
        ]);
        $customerClassPass->update([
            'status' => CustomerClassPassStatus::Active->value,
            'is_active' => false,
            'reserved_sessions_count' => 1,
            'closed_at' => null,
        ]);

        app(NormalizeCustomerClassPasses::class)->execute();

        $customerClassPass->refresh();
        $this->assertSame(CustomerClassPassStatus::Cancelled, $customerClassPass->status);
        $this->assertFalse($customerClassPass->is_active);
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertTrue($customerClassPass->closed_at->equalTo(Carbon::parse('2026-06-20 10:00:00')));
        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation->fresh()->status);
        $this->assertTrue($reservation->fresh()->released_at->equalTo(Carbon::parse('2026-06-20 10:00:00')));

        Carbon::setTestNow();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        $trainer = Trainer::factory()->for($account)->for($trainerType)->create();
        $customer = Customer::factory()->for($account)->create(['name' => 'Олена Коваль']);

        return compact('owner', 'account', 'location', 'room', 'classType', 'trainerType', 'trainer', 'customer');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function plan(
        array $context,
        int $sessions,
        ?ClassType $classType = null,
        ?TrainerType $trainerType = null,
        ?Room $room = null,
        int $totalValidityDays = 180,
    ): ClassPassPlan {
        $targetClassType = $classType ?? $context['classType'];
        $plan = ClassPassPlan::factory()->for($context['account'])->create([
            'sessions_count' => $sessions,
            'total_validity_days' => $totalValidityDays,
            'schedule_kind' => $targetClassType->schedule_kind->value,
        ]);
        $plan->classTypes()->sync([$targetClassType->id]);
        $plan->trainerTypes()->sync($trainerType === null ? ($classType?->schedule_kind?->value === 'room_rental' ? [] : [$context['trainerType']->id]) : [$trainerType->id]);
        $plan->rooms()->sync($room ? [$room->id] : []);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scheduledClass(array $context, string $startsAt, ?ClassType $classType = null, ?Trainer $trainer = null, ?Room $room = null): ScheduledClass
    {
        $startsAt = Carbon::parse($startsAt);

        return ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($room ?? $context['room'])
            ->for($classType ?? $context['classType'])
            ->for($trainer ?? $context['trainer'])
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function unlinkedBooking(array $context, ScheduledClass $scheduledClass, string $status = 'booked'): ClassBooking
    {
        return $scheduledClass->classBookings()->create([
            'account_id' => $context['account']->id,
            'customer_id' => $context['customer']->id,
            'status' => $status,
            'attended_at' => $status === 'attended' ? $scheduledClass->starts_at : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function bookingWithUsedReservation(array $context, mixed $customerClassPass, string $usedAt): ClassBooking
    {
        $scheduledClass = $this->scheduledClass($context, $usedAt);
        $booking = $scheduledClass->classBookings()->create([
            'account_id' => $context['account']->id,
            'customer_id' => $context['customer']->id,
            'status' => 'attended',
            'attended_at' => Carbon::parse($usedAt),
        ]);

        $customerClassPass->reservations()->create([
            'account_id' => $context['account']->id,
            'class_booking_id' => $booking->id,
            'scheduled_class_id' => $scheduledClass->id,
            'status' => 'used',
            'reserved_at' => Carbon::parse($usedAt)->subDay(),
            'used_at' => Carbon::parse($usedAt),
        ]);

        return $booking;
    }
}
