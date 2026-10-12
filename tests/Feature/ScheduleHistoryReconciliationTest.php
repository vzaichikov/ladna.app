<?php

namespace Tests\Feature;

use App\Actions\ReconcileDeletedScheduleSeriesHistory;
use App\Models\Account;
use App\Models\ClassBookingCorrection;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\Trainer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleHistoryReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 12:00:00');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preview_is_read_only_and_apply_restores_original_expired_pass_once(): void
    {
        [$manifest, $pass, $account] = $this->fixture();
        $otherPass = CustomerClassPass::factory()->for($account)->create(['customer_id' => $pass->customer_id]);
        $action = app(ReconcileDeletedScheduleSeriesHistory::class);
        $beforeClasses = $account->scheduledClasses()->count();
        $preview = $action->execute($manifest);
        $this->assertSame('repairable', $preview['classes'][0]['status']);
        $this->assertSame($beforeClasses, $account->scheduledClasses()->count());
        $this->assertSame(0, $pass->fresh()->used_sessions_count);

        $action->execute($manifest, apply: true);
        $repeated = $action->execute($manifest, apply: true);
        $this->assertSame('already_repaired', $repeated['classes'][0]['bookings'][0]['status']);
        $class = $account->scheduledClasses()->sole();
        $booking = $class->classBookings()->sole();
        $reservation = $booking->classPassReservation()->firstOrFail();
        $this->assertSame($pass->id, $reservation->customer_class_pass_id);
        $this->assertSame('used', $reservation->status->value);
        $this->assertSame('2026-08-02 08:00:26', $reservation->used_at->toDateTimeString());
        $this->assertSame('2026-08-02 08:00:26', $booking->attended_at->toDateTimeString());
        $this->assertNull($class->schedule_series_id);
        $this->assertFalse($class->is_generated);
        $this->assertSame(1, $pass->fresh()->used_sessions_count);
        $this->assertSame(2, $pass->fresh()->sessions_count);
        $this->assertFalse($pass->fresh()->is_active);
        $this->assertSame(0, $otherPass->fresh()->used_sessions_count);
        $this->assertSame(1, ClassBookingCorrection::whereBelongsTo($account)->count());
        Mail::assertNothingOutgoing();
    }

    public function test_cancelled_booking_is_restored_without_consuming_a_session(): void
    {
        [$manifest, $pass, $account] = $this->fixture();
        $manifest['classes'][0]['attributes']['status'] = 'cancelled';
        $manifest['classes'][0]['bookings'][0]['attributes']['status'] = 'cancelled';
        $manifest['classes'][0]['bookings'][0]['attributes']['attended_at'] = null;
        $manifest['classes'][0]['bookings'][0]['reservation']['status'] = 'released';
        $manifest['classes'][0]['bookings'][0]['reservation']['used_at'] = null;
        $manifest['classes'][0]['bookings'][0]['reservation']['released_at'] = '2026-08-01 12:00:00';
        app(ReconcileDeletedScheduleSeriesHistory::class)->execute($manifest, apply: true);
        $this->assertSame('cancelled', $account->scheduledClasses()->sole()->status->value);
        $this->assertSame(0, $pass->fresh()->used_sessions_count);
    }

    public function test_missing_pass_or_foreign_customer_is_conflicting_and_apply_rolls_back(): void
    {
        [$manifest, $pass, $account] = $this->fixture();
        $second = $manifest['classes'][0]['bookings'][0];
        $second['original_booking_id'] = 623;
        $second['attributes']['customer_id'] = Customer::factory()->create()->id;
        $manifest['classes'][0]['bookings'][] = $second;
        $action = app(ReconcileDeletedScheduleSeriesHistory::class);
        $preview = $action->execute($manifest);
        $this->assertSame('conflicting', $preview['classes'][0]['bookings'][1]['status']);
        try {
            $action->execute($manifest, apply: true);
            $this->fail('Conflicting repair must fail.');
        } catch (ValidationException) {
            $this->assertSame(0, $account->scheduledClasses()->count());
            $this->assertSame(0, $pass->fresh()->used_sessions_count);
        }
    }

    public function test_overdrawn_pass_is_reported_and_later_snapshot_dates_are_unsupported(): void
    {
        [$manifest, $pass] = $this->fixture();
        $pass->update(['sessions_count' => 0]);
        $preview = app(ReconcileDeletedScheduleSeriesHistory::class)->execute($manifest);
        $this->assertSame('conflicting', $preview['classes'][0]['bookings'][0]['status']);
        $manifest['classes'][0]['attributes']['starts_at'] = '2026-09-13 10:00:00';
        $manifest['classes'][0]['attributes']['ends_at'] = '2026-09-13 11:00:00';
        $preview = app(ReconcileDeletedScheduleSeriesHistory::class)->execute($manifest);
        $this->assertSame('unsupported', $preview['classes'][0]['status']);
    }

    public function test_cached_original_pass_cannot_be_reused_for_another_customer(): void
    {
        [$manifest, $pass, $account] = $this->fixture();
        $second = $manifest['classes'][0]['bookings'][0];
        $second['original_booking_id'] = 623;
        $second['attributes']['customer_id'] = Customer::factory()->for($account)->create()->id;
        $manifest['classes'][0]['bookings'][] = $second;
        $preview = app(ReconcileDeletedScheduleSeriesHistory::class)->execute($manifest);
        $this->assertSame('conflicting', $preview['classes'][0]['bookings'][1]['status']);
    }

    public function test_unexplained_existing_attendance_timestamp_is_conflicting(): void
    {
        [$manifest, $pass, $account] = $this->fixture();
        $class = $account->scheduledClasses()->create($manifest['classes'][0]['attributes']);
        $booking = $class->classBookings()->create(['account_id' => $account->id, ...$manifest['classes'][0]['bookings'][0]['attributes'], 'attended_at' => '2026-08-02 11:00:00']);
        $booking->classPassReservation()->create(['account_id' => $account->id, 'scheduled_class_id' => $class->id, ...$manifest['classes'][0]['bookings'][0]['reservation']]);
        $preview = app(ReconcileDeletedScheduleSeriesHistory::class)->execute($manifest);
        $this->assertSame('conflicting', $preview['classes'][0]['bookings'][0]['status']);
    }

    public function test_removed_booking_with_keep_consumed_usage_is_preserved(): void
    {
        [$manifest, $pass, $account] = $this->fixture();
        $manifest['classes'][0]['bookings'][0]['attributes']['corrected_removed_at'] = '2026-08-03 12:00:00';
        app(ReconcileDeletedScheduleSeriesHistory::class)->execute($manifest, apply: true);
        $booking = $account->scheduledClasses()->sole()->classBookings()->sole();
        $this->assertNotNull($booking->corrected_removed_at);
        $this->assertSame('used', $booking->classPassReservation()->firstOrFail()->status->value);
        $this->assertSame(1, $pass->fresh()->used_sessions_count);
    }

    public function test_duplicate_source_occurrences_and_customers_are_conflicting(): void
    {
        [$manifest] = $this->fixture();
        $duplicate = $manifest['classes'][0];
        $duplicate['original_class_id'] = 514;
        $duplicate['bookings'] = [];
        $manifest['classes'][] = $duplicate;
        $preview = app(ReconcileDeletedScheduleSeriesHistory::class)->execute($manifest);
        $this->assertSame('conflicting', $preview['classes'][1]['status']);
        array_pop($manifest['classes']);
        $duplicateBooking = $manifest['classes'][0]['bookings'][0];
        $duplicateBooking['original_booking_id'] = 623;
        $manifest['classes'][0]['bookings'][] = $duplicateBooking;
        $preview = app(ReconcileDeletedScheduleSeriesHistory::class)->execute($manifest);
        $this->assertSame('conflicting', $preview['classes'][0]['bookings'][1]['status']);
    }

    public function test_documented_prior_recovery_is_retained_without_another_debit(): void
    {
        [$manifest, $pass, $account] = $this->fixture();
        $class = $account->scheduledClasses()->create($manifest['classes'][0]['attributes']);
        $booking = $class->classBookings()->create(['account_id' => $account->id, ...$manifest['classes'][0]['bookings'][0]['attributes'], 'attended_at' => '2026-08-02 10:00:00']);
        $booking->classPassReservation()->create(['account_id' => $account->id, 'scheduled_class_id' => $class->id, ...$manifest['classes'][0]['bookings'][0]['reservation'], 'used_at' => '2026-08-02 10:00:00']);
        ClassBookingCorrection::query()->create(['account_id' => $account->id, 'scheduled_class_id' => $class->id, 'class_booking_id' => $booking->id, 'new_customer_class_pass_id' => $pass->id, 'action' => 'added', 'pass_effect' => 'auto_matched', 'reason' => 'Restore original series #40, class #513, booking #622 from fixture-2026-09-09.']);
        $report = app(ReconcileDeletedScheduleSeriesHistory::class)->execute($manifest, apply: true);
        $this->assertSame('already_repaired', $report['classes'][0]['bookings'][0]['status']);
        $this->assertArrayHasKey('used_at', $report['classes'][0]['bookings'][0]['timestamp_differences']);
        $this->assertSame(1, $pass->fresh()->used_sessions_count);
        $this->assertSame('2026-08-02 10:00:00', $booking->classPassReservation()->firstOrFail()->used_at->toDateTimeString());
        $this->assertSame(1, $booking->classPassReservation()->count());
    }

    public function test_command_defaults_to_preview_and_rejects_another_account(): void
    {
        [$manifest, $pass, $account] = $this->fixture();
        $path = tempnam(sys_get_temp_dir(), 'ladna-history-');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        try {
            $this->artisan('schedule:reconcile-history', ['account' => $account->id, 'manifest' => $path])->expectsOutputToContain('"applied": false')->assertSuccessful();
            $this->assertSame(0, $account->scheduledClasses()->count());
            $this->assertSame(0, $pass->fresh()->used_sessions_count);
            $this->artisan('schedule:reconcile-history', ['account' => $account->id + 1, 'manifest' => $path])->assertFailed();
        } finally {
            unlink($path);
        }
    }

    public function test_different_removal_timestamp_conflicts_even_with_recovery_provenance(): void
    {
        [$manifest, $pass, $account] = $this->fixture();
        $manifest['classes'][0]['bookings'][0]['attributes']['corrected_removed_at'] = '2026-08-03 12:00:00';
        $class = $account->scheduledClasses()->create($manifest['classes'][0]['attributes']);
        $booking = $class->classBookings()->create(['account_id' => $account->id, ...$manifest['classes'][0]['bookings'][0]['attributes'], 'corrected_removed_at' => '2026-08-04 12:00:00']);
        $booking->classPassReservation()->create(['account_id' => $account->id, 'scheduled_class_id' => $class->id, ...$manifest['classes'][0]['bookings'][0]['reservation']]);
        ClassBookingCorrection::query()->create(['account_id' => $account->id, 'scheduled_class_id' => $class->id, 'class_booking_id' => $booking->id, 'new_customer_class_pass_id' => $pass->id, 'action' => 'added', 'pass_effect' => 'auto_matched', 'reason' => 'Restore original booking #622.']);
        $preview = app(ReconcileDeletedScheduleSeriesHistory::class)->execute($manifest);
        $this->assertSame('conflicting', $preview['classes'][0]['bookings'][0]['status']);
        $this->assertArrayHasKey('corrected_removed_at', $preview['classes'][0]['bookings'][0]['timestamp_differences']);
    }

    /** @return array{array<string, mixed>, CustomerClassPass, Account} */
    private function fixture(): array
    {
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $trainer = Trainer::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $pass = CustomerClassPass::factory()->for($account)->for($customer)->create([
            'purchased_at' => '2026-08-01', 'sessions_count' => 2, 'status' => 'expired', 'is_active' => false,
        ]);
        $manifest = [
            'account_id' => $account->id, 'original_series_id' => 40, 'source_backup' => 'fixture-2026-09-09', 'snapshot_at' => '2026-09-09 11:00:00',
            'classes' => [[
                'original_class_id' => 513,
                'attributes' => ['location_id' => $location->id, 'room_id' => $room->id, 'class_type_id' => $classType->id, 'trainer_id' => $trainer->id, 'title' => 'Historical class', 'starts_at' => '2026-08-02 10:00:00', 'ends_at' => '2026-08-02 11:00:00', 'status' => 'scheduled', 'capacity' => 10],
                'bookings' => [[
                    'original_booking_id' => 622,
                    'attributes' => ['customer_id' => $customer->id, 'status' => 'attended', 'attended_at' => '2026-08-02 08:00:26', 'skip_class_pass_reservation' => false, 'corrected_removed_at' => null],
                    'reservation' => ['customer_class_pass_id' => $pass->id, 'status' => 'used', 'reserved_at' => '2026-08-01 12:00:00', 'used_at' => '2026-08-02 08:00:26', 'released_at' => null],
                ]],
            ]],
        ];

        return [$manifest, $pass, $account];
    }
}
