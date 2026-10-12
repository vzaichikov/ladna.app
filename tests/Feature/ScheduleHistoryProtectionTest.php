<?php

namespace Tests\Feature;

use App\Actions\DeleteScheduleSeries;
use App\Actions\GenerateAccountSchedule;
use App\Actions\GenerateScheduleOccurrences;
use App\Actions\NormalizeCustomerClassPasses;
use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\StudioPermission;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduleSeries;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ScheduleHistoryProtectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00', 'UTC'));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_deleting_series_preserves_started_classes_and_pass_usage_and_cancels_future_bookings(): void
    {
        $context = $this->context();
        $historical = [];
        foreach (['2026-10-11 12:00:00', '2026-10-12 09:00:00', '2026-10-12 11:30:00', '2026-10-12 12:00:00'] as $time) {
            $class = $this->occurrence($context, $time);
            $booking = $this->booking($context, $class, $time < '2026-10-12 11:00:00');
            $historical[] = [$class, $booking, $booking->classPassReservation->getAttributes()];
        }
        $future = $this->occurrence($context, '2026-10-12 14:00:00');
        $futureBooking = $this->booking($context, $future);
        $empty = $this->occurrence($context, '2026-10-19 14:00:00');
        app(NormalizeCustomerClassPasses::class)->forPass($context['pass']);

        $this->actingAs($context['owner'])->delete(route('dashboard.accounts.schedule-series.destroy', [$context['account'], $context['series']]))->assertRedirect();

        foreach ($historical as [$class, $booking, $reservationAttributes]) {
            $this->assertModelExists($class);
            $this->assertSame($context['trainer']->id, $class->fresh()->trainer_id);
            $this->assertSame(ScheduledClassStatus::Scheduled, $class->fresh()->status);
            $this->assertSame($booking->status, $booking->fresh()->status);
            $this->assertSame($reservationAttributes, $booking->classPassReservation()->firstOrFail()->getAttributes());
        }
        $this->assertModelMissing($context['series']);
        $this->assertModelMissing($empty);
        $this->assertSame(ScheduledClassStatus::Cancelled, $future->fresh()->status);
        $this->assertSame(ClassBookingStatus::Cancelled, $futureBooking->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Released, $futureBooking->classPassReservation()->firstOrFail()->status);
        $this->assertSame(2, $context['pass']->fresh()->used_sessions_count);
        $this->assertSame(2, $context['pass']->fresh()->reserved_sessions_count);
    }

    public function test_regeneration_does_not_rewrite_or_recreate_classes_that_started_today(): void
    {
        $context = $this->context();
        $class = $this->occurrence($context, '2026-10-12 09:00:00');
        $attributes = $class->fresh()->getAttributes();
        $replacement = Trainer::factory()->for($context['account'])->create();
        $context['series']->update(['start_time' => '12:00', 'trainer_id' => $replacement->id]);
        app(GenerateAccountSchedule::class)->execute($context['account']);
        $this->assertSame($attributes, $class->fresh()->getAttributes());
        $this->assertSame(1, $context['account']->scheduledClasses()->where('starts_at', '<=', now())->count());
        $this->assertSame(0, $context['account']->scheduledClasses()->where('starts_at', now())->count());
        $this->assertTrue($context['series']->scheduledClasses()->where('starts_at', '>', now())->where('trainer_id', $replacement->id)->exists());
    }

    public function test_time_change_cancels_booked_obsolete_occurrence_without_double_compensation(): void
    {
        $context = $this->context();
        $future = $this->occurrence($context, '2026-10-12 16:00:00');
        $booking = $this->booking($context, $future);
        $context['series']->update(['start_time' => '20:00']);
        app(GenerateScheduleOccurrences::class)->execute($context['series']->fresh());
        app(GenerateScheduleOccurrences::class)->execute($context['series']->fresh());
        $this->assertSame(ScheduledClassStatus::Cancelled, $future->fresh()->status);
        $this->assertSame(ClassBookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Released, $booking->classPassReservation()->firstOrFail()->status);
        $this->assertSame(1, $future->cancellations()->count());
        $this->assertSame(12, $context['pass']->fresh()->sessions_count);
        $this->assertSame(1, $context['series']->scheduledClasses()->where('starts_at', '2026-10-12 17:00:00')->count());
    }

    public function test_pausing_series_cancels_its_future_booked_classes(): void
    {
        $context = $this->context();
        $future = $this->occurrence($context, '2026-10-12 16:00:00');
        $this->booking($context, $future);
        $context['series']->update(['status' => 'paused']);
        app(GenerateAccountSchedule::class)->execute($context['account'], $context['series']->id);
        $this->assertSame(ScheduledClassStatus::Cancelled, $future->fresh()->status);
    }

    public function test_room_and_location_deletion_cannot_cascade_scheduled_history(): void
    {
        $context = $this->context();
        $class = $this->occurrence($context, '2026-10-11 12:00:00');
        $booking = $this->booking($context, $class, true);
        $this->actingAs($context['owner'])->delete(route('dashboard.accounts.rooms.destroy', [$context['account'], $context['room']]))->assertSessionHasErrors('room');
        $this->delete(route('dashboard.accounts.locations.destroy', [$context['account'], $context['location']]))->assertSessionHasErrors('location');
        $this->assertModelExists($class);
        $this->assertModelExists($booking);
        $this->assertModelExists($booking->classPassReservation);
    }

    public function test_schedule_only_staff_cannot_cancel_bookings_by_deleting_or_editing_series(): void
    {
        $context = $this->context();
        $class = $this->occurrence($context, '2026-10-12 16:00:00');
        $booking = $this->booking($context, $class);
        $staff = User::factory()->create();
        AccountMembership::factory()->for($context['account'])->for($staff)->create(['role' => AccountRole::Receptionist->value, 'permissions' => [StudioPermission::ManageSchedule->value]]);
        $this->actingAs($staff)->delete(route('dashboard.accounts.schedule-series.destroy', [$context['account'], $context['series']]))->assertForbidden();
        $this->put(route('dashboard.accounts.schedule-series.update', [$context['account'], $context['series']]), [
            'location_id' => $context['location']->id, 'room_id' => $context['room']->id, 'class_type_id' => $context['classType']->id,
            'trainer_id' => $context['trainer']->id, 'weekday' => 1, 'start_time' => '20:00', 'start_date' => '2026-10-01', 'status' => 'active',
        ])->assertForbidden();
        $this->assertModelExists($context['series']);
        $this->assertSame('19:00', substr($context['series']->fresh()->start_time, 0, 5));
        $this->assertSame(ClassBookingStatus::Booked, $booking->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $booking->classPassReservation()->firstOrFail()->status);
    }

    public function test_failed_outer_transaction_preserves_series_bookings_and_sends_no_cancellation(): void
    {
        $context = $this->context();
        $class = $this->occurrence($context, '2026-10-12 16:00:00');
        $booking = $this->booking($context, $class);
        try {
            DB::transaction(function () use ($context): void {
                app(DeleteScheduleSeries::class)->execute($context['account'], $context['series'], $context['owner']);
                throw new \RuntimeException('Rollback the batch.');
            });
        } catch (\RuntimeException) {
            $this->assertModelExists($context['series']);
        }
        $this->assertSame(ClassBookingStatus::Booked, $booking->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $booking->classPassReservation()->firstOrFail()->status);
        Mail::assertNothingOutgoing();
    }

    public function test_permanent_trainer_update_preserves_bookings_and_survives_generation_and_dst(): void
    {
        $context = $this->context();
        $class = $this->occurrence($context, '2026-10-12 16:00:00');
        $booking = $this->booking($context, $class);
        $reservation = $booking->classPassReservation->getAttributes();
        $replacement = Trainer::factory()->for($context['account'])->create();
        $context['series']->update(['trainer_id' => $replacement->id]);
        app(GenerateAccountSchedule::class)->execute($context['account']);
        app(GenerateAccountSchedule::class)->execute($context['account']);
        $this->assertSame($replacement->id, $class->fresh()->trainer_id);
        $this->assertSame(ClassBookingStatus::Booked, $booking->fresh()->status);
        $this->assertSame($reservation, $booking->classPassReservation()->firstOrFail()->getAttributes());
        $this->assertTrue($context['series']->scheduledClasses()->where('starts_at', '2026-10-26 17:00:00')->exists());
        $this->assertSame(0, $class->cancellations()->count());
    }

    public function test_future_retirement_does_not_settle_unrelated_past_reserved_ledger_rows(): void
    {
        $context = $this->context();
        $past = $this->occurrence($context, '2026-10-11 12:00:00');
        $pastBooking = $this->booking($context, $past);
        $before = $pastBooking->classPassReservation->getAttributes();
        $future = $this->occurrence($context, '2026-10-12 16:00:00');
        $this->booking($context, $future);
        app(DeleteScheduleSeries::class)->execute($context['account'], $context['series'], $context['owner']);
        $this->assertSame($before, $pastBooking->classPassReservation()->firstOrFail()->getAttributes());
    }

    public function test_pausing_series_cancels_future_manual_overrides_too(): void
    {
        $context = $this->context();
        $class = $this->occurrence($context, '2026-10-12 16:00:00');
        $class->update(['is_manually_modified' => true]);
        $this->booking($context, $class);
        $context['series']->update(['status' => 'paused']);
        app(GenerateAccountSchedule::class)->execute($context['account'], $context['series']->id);
        $this->assertSame(ScheduledClassStatus::Cancelled, $class->fresh()->status);
    }

    public function test_regeneration_preserves_cancelled_occurrence_even_without_manual_flag(): void
    {
        $context = $this->context();
        $class = $this->occurrence($context, '2026-10-12 16:00:00');
        $class->update(['status' => 'cancelled', 'is_manually_modified' => false]);
        app(GenerateAccountSchedule::class)->execute($context['account']);
        $this->assertSame(ScheduledClassStatus::Cancelled, $class->fresh()->status);
    }

    public function test_cancellation_mail_is_sent_only_after_successful_outer_commit(): void
    {
        $context = $this->context();
        $class = $this->occurrence($context, '2026-10-12 16:00:00');
        $this->booking($context, $class);
        DB::transaction(function () use ($context): void {
            app(DeleteScheduleSeries::class)->execute($context['account'], $context['series'], $context['owner']);
            Mail::assertNothingOutgoing();
        });
        Mail::assertQueued(TransactionalMail::class);
    }

    public function test_failed_substitution_resync_rolls_back_the_substitution(): void
    {
        $context = $this->context();
        $orphan = $this->occurrence($context, '2026-10-12 16:00:00');
        $orphan->update(['schedule_series_id' => null]);
        $booking = $this->booking($context, $orphan);
        $replacement = Trainer::factory()->for($context['account'])->create();
        $staff = User::factory()->create();
        AccountMembership::factory()->for($context['account'])->for($staff)->create(['role' => AccountRole::Receptionist->value, 'permissions' => [StudioPermission::ManageTrainers->value]]);
        $this->actingAs($staff)->post(route('dashboard.accounts.trainers.substitutions.store', [$context['account'], $context['trainer']]), [
            'mode' => 'period', 'location_id' => $context['location']->id, 'room_id' => $context['room']->id, 'substitute_trainer_id' => $replacement->id,
            'date_from' => '2026-10-13', 'date_to' => '2026-10-15', 'class_type_ids' => [$context['classType']->id],
        ])->assertForbidden();
        $this->assertSame(0, $context['account']->trainerSubstitutions()->count());
        $this->assertSame(ClassBookingStatus::Booked, $booking->fresh()->status);
    }

    /** @return array{account: Account, owner: User, location: Location, room: Room, classType: ClassType, trainer: Trainer, series: ScheduleSeries, customer: Customer, pass: CustomerClassPass} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv', 'class_pass_cancellation_rules' => ['return_sessions_enabled' => true, 'return_sessions_count' => 1]]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'Europe/Kyiv']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class', 'default_duration_minutes' => 60]);
        $trainer = Trainer::factory()->for($account)->create();
        $series = ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->for($trainer)->create(['weekday' => 1, 'start_time' => '19:00', 'start_date' => '2026-10-01']);
        $customer = Customer::factory()->for($account)->create();
        $pass = CustomerClassPass::factory()->for($account)->for($customer)->create(['sessions_count' => 12, 'purchased_at' => '2026-10-01']);

        return compact('owner', 'account', 'location', 'room', 'classType', 'trainer', 'series', 'customer', 'pass');
    }

    /** @param array<string, mixed> $context */
    private function occurrence(array $context, string $startsAt): ScheduledClass
    {
        return ScheduledClass::factory()->for($context['account'])->for($context['location'])->for($context['room'])->for($context['classType'])->for($context['trainer'])->create([
            'schedule_series_id' => $context['series']->id, 'starts_at' => $startsAt, 'ends_at' => Carbon::parse($startsAt, 'UTC')->addHour(), 'is_generated' => true, 'is_manually_modified' => false,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function booking(array $context, ScheduledClass $class, bool $attended = false): ClassBooking
    {
        $booking = ClassBooking::factory()->for($context['account'])->for($class, 'scheduledClass')->for($context['customer'])->create(['status' => $attended ? 'attended' : 'booked', 'attended_at' => $attended ? $class->starts_at : null]);
        CustomerClassPassReservation::factory()->for($context['account'])->for($context['pass'], 'customerClassPass')->for($class, 'scheduledClass')->for($booking, 'classBooking')->create(['status' => $attended ? 'used' : 'reserved', 'used_at' => $attended ? $class->starts_at : null]);

        return $booking;
    }
}
