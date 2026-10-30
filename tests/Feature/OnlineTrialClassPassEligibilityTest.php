<?php

namespace Tests\Feature;

use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\ScheduledClass;
use App\Support\CustomerBookingLedgerInvestigation;
use App\Support\TrialClassPassEligibility;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OnlineTrialClassPassEligibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('bookingCases')]
    public function test_online_trial_requires_one_future_booking_without_an_active_reservation(
        int $startOffsetMinutes,
        int $bookingCount,
        ?string $reservationStatus,
        bool $eligible,
    ): void {
        Carbon::setTestNow('2026-10-30 13:00:00');
        $account = Account::factory()->create();
        $customer = Customer::factory()->for($account)->create();
        $type = ClassType::factory()->for($account)->create();
        for ($index = 0; $index < $bookingCount; $index++) {
            $class = ScheduledClass::factory()->recycle($account)->for($account)->for($type, 'classType')->create([
                'starts_at' => now()->addMinutes($startOffsetMinutes + $index),
                'ends_at' => now()->addMinutes($startOffsetMinutes + $index + 60),
            ]);
            $booking = ClassBooking::factory()->recycle($account)->for($account)->for($customer)->for($class, 'scheduledClass')->create();
            if ($reservationStatus !== null) {
                $pass = CustomerClassPass::factory()->for($account)->for($customer)->create();
                $pass->reservations()->create([
                    'account_id' => $account->id,
                    'class_booking_id' => $booking->id,
                    'scheduled_class_id' => $class->id,
                    'status' => $reservationStatus,
                    'reserved_at' => now()->subMinute(),
                    'used_at' => $reservationStatus === 'used' ? now()->subMinute() : null,
                    'released_at' => $reservationStatus === 'released' ? now()->subSeconds(30) : null,
                ]);
            }
        }

        $result = app(TrialClassPassEligibility::class)->evaluate($account, $customer, TrialClassPassEligibility::SourceOnlinePayment);

        $this->assertSame($eligible ? 'eligible' : 'ineligible', $result['status']);
        $this->assertSame($bookingCount, $result['counted_bookings_count']);
        if ($eligible && $bookingCount === 1) {
            $this->assertSame(['single_future_unreserved_booking_online_exception'], $result['reason_codes']);
        }
    }

    /** @return array<string, array{int, int, ?string, bool}> */
    public static function bookingCases(): array
    {
        return [
            'no bookings' => [60, 0, null, true],
            'one future booking' => [60, 1, null, true],
            'starts now' => [0, 1, null, false],
            'already started' => [-1, 1, null, false],
            'past booking' => [-120, 1, null, false],
            'two future bookings' => [60, 2, null, false],
            'reserved session' => [60, 1, CustomerClassPassReservationStatus::Reserved->value, false],
            'used session' => [60, 1, CustomerClassPassReservationStatus::Used->value, false],
            'released session' => [60, 1, CustomerClassPassReservationStatus::Released->value, true],
        ];
    }

    public function test_future_booking_eligibility_ignores_rental_history_and_uses_the_payment_start_time(): void
    {
        Carbon::setTestNow('2026-10-30 13:00:00');
        $account = Account::factory()->create();
        $customer = Customer::factory()->for($account)->create();
        foreach ([ScheduleKind::RoomRental, ScheduleKind::GroupClass] as $kind) {
            $type = ClassType::factory()->for($account)->create(['schedule_kind' => $kind]);
            $class = ScheduledClass::factory()->recycle($account)->for($account)->for($type, 'classType')->create([
                'starts_at' => now()->addHour(),
                'ends_at' => now()->addHours(2),
            ]);
            ClassBooking::factory()->recycle($account)->for($account)->for($customer)->for($class, 'scheduledClass')->create();
        }
        $asOf = now();
        Carbon::setTestNow(now()->addHours(3));
        $eligibility = app(TrialClassPassEligibility::class);

        $historical = $eligibility->evaluate($account, $customer, TrialClassPassEligibility::SourceOnlinePayment, $asOf);
        $this->assertSame('eligible', $historical['status']);
        $this->assertSame(1, $historical['counted_bookings_count']);
        $this->assertSame('ineligible', $eligibility->evaluate($account, $customer, TrialClassPassEligibility::SourceOnlinePayment)['status']);
    }

    public function test_historical_online_exception_reports_the_reservation_history_limitation(): void
    {
        Carbon::setTestNow('2026-10-30 13:00:00');
        $account = Account::factory()->create();
        $customer = Customer::factory()->for($account)->create();
        ClassPassPlan::factory()->for($account)->create(['is_trial' => true]);
        $class = ScheduledClass::factory()->recycle($account)->for($account)->create([
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);
        ClassBooking::factory()->recycle($account)->for($account)->for($customer)->for($class, 'scheduledClass')->create();
        $asOf = now();
        Carbon::setTestNow(now()->addHours(3));

        $result = app(CustomerBookingLedgerInvestigation::class)->investigate(
            $account, $customer->id, asOf: $asOf->toIso8601String(), source: TrialClassPassEligibility::SourceOnlinePayment,
        );

        $this->assertSame('eligible', $result['trial_eligibility']['status']);
        $this->assertFalse($result['trial_eligibility']['evidence_complete']);
        $this->assertFalse($result['trial_eligibility']['historical_reconstruction']['reservation_history_complete']);
    }
}
