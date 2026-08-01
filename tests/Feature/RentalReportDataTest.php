<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Support\Finance\RentalReportData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RentalReportDataTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_report_calculates_unpaid_partial_paid_and_refunded_rentals_by_currency(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'UTC'));

        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $main = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $secondary = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $trainer = Trainer::factory()->for($account)->create();
        $classType = $this->rentalClassType($account);

        [$unpaidRental, $unpaidBooking] = $this->rental($account, $main, $trainer, $classType, '2026-07-10 10:00:00', 90);
        $this->reservation($account, $unpaidBooking, $unpaidRental, 10000, 0, 'UAH');

        [$partialRental, $partialBooking] = $this->rental($account, $main, $trainer, $classType, '2026-07-11 10:00:00');
        $this->reservation($account, $partialBooking, $partialRental, 10000, 4000, 'USD');

        [$paidRental, $paidBooking] = $this->rental($account, $main, $trainer, $classType, '2026-07-12 10:00:00');
        $this->reservation($account, $paidBooking, $paidRental, 12000, 12000, 'UAH');

        [, $partiallyRefundedBooking] = $this->rental($account, $main, $trainer, $classType, '2026-07-13 10:00:00');
        $partiallyRefundedPayment = $this->directPayment($account, $main, $partiallyRefundedBooking, 6000, 'USD');
        $this->refund($account, $main, $partiallyRefundedPayment, 2000, 'USD');

        [, $fullyRefundedBooking] = $this->rental($account, $main, $trainer, $classType, '2026-07-14 10:00:00');
        $fullyRefundedPayment = $this->directPayment($account, $main, $fullyRefundedBooking, 7000, 'UAH');
        $this->refund($account, $main, $fullyRefundedPayment, 7000, 'UAH');

        [$cancelledRental, $cancelledBooking] = $this->rental(
            $account,
            $main,
            $trainer,
            $classType,
            '2026-07-15 10:00:00',
            status: ScheduledClassStatus::Cancelled,
        );
        $this->directPayment($account, $main, $cancelledBooking, 99000, 'UAH');

        [$otherLocationRental, $otherLocationBooking] = $this->rental(
            $account,
            $secondary,
            $trainer,
            $classType,
            '2026-07-16 10:00:00',
        );
        $this->directPayment($account, $secondary, $otherLocationBooking, 88000, 'UAH');

        [$futureRental, $futureBooking] = $this->rental($account, $main, $trainer, $classType, '2026-08-01 11:30:00');
        $futureRental->update(['ends_at' => '2026-08-01 12:30:00']);
        $this->directPayment($account, $main, $futureBooking, 77000, 'UAH');

        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $otherLocation = Location::factory()->for($otherAccount)->create();
        $otherTrainer = Trainer::factory()->for($otherAccount)->create();
        $otherClassType = $this->rentalClassType($otherAccount);
        [, $otherBooking] = $this->rental($otherAccount, $otherLocation, $otherTrainer, $otherClassType, '2026-07-17 10:00:00');
        $this->directPayment($otherAccount, $otherLocation, $otherBooking, 66000, 'UAH');

        $report = $this->report(
            $account,
            Carbon::parse('2026-07-01 00:00:00', 'UTC'),
            Carbon::parse('2026-08-01 23:59:59', 'UTC'),
            $main,
        );

        $this->assertSame(['UAH' => 29000, 'USD' => 16000], $report['totals']['accrued']);
        $this->assertSame(['UAH' => 19000, 'USD' => 10000], $report['totals']['paid']);
        $this->assertSame(['UAH' => 7000, 'USD' => 2000], $report['totals']['refunded']);
        $this->assertSame(['UAH' => 17000, 'USD' => 8000], $report['totals']['debt']);
        $this->assertSame(
            ['unpaid', 'partially_paid', 'paid', 'partially_paid', 'refunded'],
            $report['rows']->sortBy('starts_at')->pluck('status')->values()->all(),
        );
        $this->assertSame(90, $report['rows']->firstWhere('booking.id', $unpaidBooking->id)['duration_minutes']);
        $this->assertNotContains($cancelledRental->id, $report['rows']->pluck('scheduled_class.id'));
        $this->assertNotContains($otherLocationRental->id, $report['rows']->pluck('scheduled_class.id'));
        $this->assertNotContains($futureRental->id, $report['rows']->pluck('scheduled_class.id'));
    }

    public function test_class_pass_allocation_uses_global_reservation_position_across_period_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'UTC'));

        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $trainer = Trainer::factory()->for($account)->create();
        $classType = $this->rentalClassType($account);
        $customer = Customer::factory()->for($account)->create();
        $classPass = CustomerClassPass::factory()->for($account)->for($customer)->create([
            'class_pass_plan_id' => null,
            'price_cents' => 1001,
            'paid_amount_cents' => 501,
            'currency' => 'UAH',
            'sessions_count' => 3,
        ]);

        foreach (['2026-06-30 10:00:00', '2026-07-10 10:00:00', '2026-07-11 10:00:00'] as $startsAt) {
            [$rental, $booking] = $this->rental($account, $location, $trainer, $classType, $startsAt, customer: $customer);
            CustomerClassPassReservation::factory()
                ->for($account)
                ->for($classPass)
                ->for($booking)
                ->for($rental)
                ->create([
                    'status' => CustomerClassPassReservationStatus::Used->value,
                    'reserved_at' => $rental->starts_at->copy()->subDay(),
                    'used_at' => $rental->ends_at,
                ]);
        }

        $report = $this->report(
            $account,
            Carbon::parse('2026-07-01 00:00:00', 'UTC'),
            Carbon::parse('2026-07-31 23:59:59', 'UTC'),
            $location,
        );

        $this->assertCount(2, $report['rows']);
        $this->assertSame(['UAH' => 667], $report['totals']['accrued']);
        $this->assertSame(['UAH' => 334], $report['totals']['paid']);
        $this->assertSame(['UAH' => 333], $report['totals']['debt']);
        $this->assertSame(['partially_paid', 'partially_paid'], $report['rows']->pluck('status')->all());
    }

    private function rentalClassType(Account $account): ClassType
    {
        $activityDirection = ActivityDirection::factory()->for($account)->create();

        return ClassType::factory()
            ->for($account)
            ->for($activityDirection, 'activityDirection')
            ->create(['schedule_kind' => ScheduleKind::RoomRental->value]);
    }

    /**
     * @return array{0: ScheduledClass, 1: ClassBooking}
     */
    private function rental(
        Account $account,
        Location $location,
        Trainer $trainer,
        ClassType $classType,
        string $startsAt,
        int $durationMinutes = 60,
        ScheduledClassStatus $status = ScheduledClassStatus::Scheduled,
        ?Customer $customer = null,
    ): array {
        $room = Room::factory()->for($account)->for($location)->create();
        $start = Carbon::parse($startsAt, 'UTC');
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($trainer)
            ->for($classType)
            ->create([
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes($durationMinutes),
                'status' => $status->value,
            ]);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer ?? Customer::factory()->for($account))
            ->create(['status' => ClassBookingStatus::Attended->value]);

        return [$scheduledClass, $booking];
    }

    private function reservation(
        Account $account,
        ClassBooking $booking,
        ScheduledClass $scheduledClass,
        int $priceCents,
        int $paidAmountCents,
        string $currency,
    ): CustomerClassPassReservation {
        $classPass = CustomerClassPass::factory()->for($account)->for($booking->customer)->create([
            'class_pass_plan_id' => null,
            'price_cents' => $priceCents,
            'paid_amount_cents' => $paidAmountCents,
            'currency' => $currency,
            'sessions_count' => 1,
        ]);

        return CustomerClassPassReservation::factory()
            ->for($account)
            ->for($classPass)
            ->for($booking)
            ->for($scheduledClass)
            ->create([
                'status' => CustomerClassPassReservationStatus::Used->value,
                'reserved_at' => $scheduledClass->starts_at->copy()->subDay(),
                'used_at' => $scheduledClass->ends_at,
            ]);
    }

    private function directPayment(
        Account $account,
        Location $location,
        ClassBooking $booking,
        int $amountCents,
        string $currency,
    ): CustomerPurchase {
        return CustomerPurchase::factory()->for($account)->for($booking->customer)->for($location)->create([
            'class_pass_plan_id' => null,
            'class_booking_id' => $booking->id,
            'payment_source' => CustomerPurchase::SourceManualCashBooking,
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'paid_at' => $booking->scheduledClass->ends_at,
        ]);
    }

    private function refund(
        Account $account,
        Location $location,
        CustomerPurchase $purchase,
        int $amountCents,
        string $currency,
    ): CustomerPurchaseRefund {
        return CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($purchase, 'customerPurchase')
            ->for($location)
            ->create([
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'refunded_at' => $purchase->paid_at->copy()->addHour(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(Account $account, Carbon $startsAt, Carbon $endsAt, Location $location): array
    {
        return app(RentalReportData::class)->forAccount(
            $account,
            [
                'date_from' => $startsAt->toDateString(),
                'date_to' => $endsAt->toDateString(),
                'location_id' => $location->id,
            ],
            $startsAt,
            $endsAt,
        );
    }
}
