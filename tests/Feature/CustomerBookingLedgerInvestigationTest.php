<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassAdjustmentType;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassAdjustment;
use App\Models\CustomerClassPassReservation;
use App\Models\Location;
use App\Models\ScheduledClass;
use App\Support\CustomerBookingLedgerInvestigation;
use App\Support\CustomerInvestigationSearch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerBookingLedgerInvestigationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_explains_the_adjusted_old_pass_and_issuance_backfill_timeline_without_false_anomalies(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Europe/Kyiv'));

        try {
            $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
            $customer = Customer::factory()->for($account)->create(['name' => 'Test Customer']);
            $location = Location::factory()->for($account)->create();
            $classType = ClassType::factory()->for($account)->create([
                'schedule_kind' => ScheduleKind::GroupClass->value,
            ]);
            $classPassPlan = ClassPassPlan::factory()->for($account)->create([
                'sessions_count' => 6,
                'schedule_kind' => ScheduleKind::GroupClass->value,
            ]);
            $julySecond = $this->scheduledClass($account, $location, $classType, '2026-07-02 10:00:00');
            $julySeventh = $this->scheduledClass($account, $location, $classType, '2026-07-07 10:00:00');
            $julyFourteenth = $this->scheduledClass($account, $location, $classType, '2026-07-14 11:00:00');
            $oldPass = CustomerClassPass::factory()
                ->for($account)
                ->for($customer, 'customer')
                ->for($classPassPlan)
                ->create([
                    'code' => 'OLDP-0001',
                    'sessions_count' => 2,
                    'reserved_sessions_count' => 0,
                    'used_sessions_count' => 2,
                    'status' => CustomerClassPassStatus::UsedUp->value,
                    'is_active' => false,
                    'purchased_at' => Carbon::parse('2026-07-01 09:00:00', 'Europe/Kyiv')->utc(),
                    'closed_at' => Carbon::parse('2026-07-07 12:00:00', 'Europe/Kyiv')->utc(),
                    'created_at' => Carbon::parse('2026-07-01 09:00:00', 'Europe/Kyiv')->utc(),
                ]);
            CustomerClassPassAdjustment::factory()
                ->for($account)
                ->for($oldPass)
                ->create([
                    'adjustment_type' => CustomerClassPassAdjustmentType::Sessions->value,
                    'sessions_delta' => -4,
                    'previous_sessions_count' => 6,
                    'new_sessions_count' => 2,
                    'actor_name' => 'Studio owner',
                    'actor_role' => 'owner',
                    'created_at' => Carbon::parse('2026-07-02 08:30:00', 'Europe/Kyiv')->utc(),
                ]);
            $julySecondBooking = $this->booking($account, $customer, $julySecond, '2026-07-01 18:00:00', 'customer');
            $julySeventhBooking = $this->booking($account, $customer, $julySeventh, '2026-07-06 12:00:00', 'customer');
            $this->usedReservation($account, $oldPass, $julySecondBooking, $julySecond);
            $this->usedReservation($account, $oldPass, $julySeventhBooking, $julySeventh);
            $julyFourteenthBooking = $this->booking($account, $customer, $julyFourteenth, '2026-07-14 09:54:17', 'owner');
            $newPassCreatedAt = Carbon::parse('2026-07-14 09:54:49', 'Europe/Kyiv')->utc();
            $newPass = CustomerClassPass::factory()
                ->for($account)
                ->for($customer, 'customer')
                ->for($classPassPlan)
                ->create([
                    'code' => 'NEWP-0002',
                    'sessions_count' => 6,
                    'reserved_sessions_count' => 1,
                    'used_sessions_count' => 0,
                    'purchased_at' => $newPassCreatedAt,
                    'created_at' => $newPassCreatedAt,
                    'issued_by_actor_name' => 'Studio owner',
                    'issued_by_actor_role' => 'owner',
                ]);
            CustomerClassPassReservation::factory()
                ->for($account)
                ->for($newPass)
                ->for($julyFourteenthBooking)
                ->for($julyFourteenth)
                ->create([
                    'status' => CustomerClassPassReservationStatus::Reserved->value,
                    'reserved_at' => $newPassCreatedAt,
                    'created_at' => $newPassCreatedAt,
                ]);

            $result = app(CustomerBookingLedgerInvestigation::class)->investigate(
                $account,
                $customer->id,
                '2026-07-01',
                '2026-07-31',
            );

            $this->assertSame('found', $result['status']);
            $this->assertFalse($result['summary']['has_detected_anomalies']);
            $this->assertTrue($result['summary']['evidence_complete']);
            $this->assertSame(0, $result['summary']['corrections_count']);
            $this->assertSame(['OLDP-0001', 'NEWP-0002'], array_column($result['passes'], 'code'));
            $this->assertSame('OLDP-0001', $this->bookingResult($result, $julySeventhBooking->id)['reservation']['pass_code']);
            $this->assertSame('NEWP-0002', $this->bookingResult($result, $julyFourteenthBooking->id)['reservation']['pass_code']);
            $this->assertTrue(collect($result['findings'])->contains(
                fn (array $finding): bool => $finding['code'] === 'booking_consistent_with_issuance_backfill'
                    && $finding['evidence']['booking_id'] === $julyFourteenthBooking->id,
            ));
            $this->assertTrue(collect($result['findings'])->contains(
                fn (array $finding): bool => $finding['code'] === 'no_detected_ledger_inconsistencies',
            ));
            $this->assertSame(-4, $result['adjustments'][0]['sessions_delta']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_detects_pass_counter_mismatches(): void
    {
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $customer = Customer::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()->for($account)->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->for($scheduledClass)
            ->create();
        $classPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->create([
                'used_sessions_count' => 2,
                'reserved_sessions_count' => 0,
            ]);
        CustomerClassPassReservation::factory()
            ->for($account)
            ->for($classPass)
            ->for($booking)
            ->for($scheduledClass)
            ->create([
                'status' => CustomerClassPassReservationStatus::Used->value,
                'used_at' => $scheduledClass->starts_at,
            ]);

        $result = app(CustomerBookingLedgerInvestigation::class)->investigate($account, $customer->id);

        $this->assertTrue($result['summary']['has_detected_anomalies']);
        $this->assertTrue(collect($result['findings'])->contains(
            fn (array $finding): bool => $finding['code'] === 'class_pass_counter_mismatch'
                && $finding['evidence']['stored_used'] === 2
                && $finding['evidence']['ledger_used'] === 1,
        ));
    }

    public function test_it_treats_a_released_reservation_as_an_unreserved_booking(): void
    {
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $customer = Customer::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()->for($account)->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->for($scheduledClass)
            ->create();
        $classPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->create([
                'used_sessions_count' => 0,
                'reserved_sessions_count' => 0,
            ]);
        $reservation = CustomerClassPassReservation::factory()
            ->for($account)
            ->for($classPass)
            ->for($booking)
            ->for($scheduledClass)
            ->create([
                'status' => CustomerClassPassReservationStatus::Released->value,
                'released_at' => now(),
            ]);

        $result = app(CustomerBookingLedgerInvestigation::class)->investigate($account, $customer->id);
        $finding = collect($result['findings'])->firstWhere('code', 'unreserved_booking');

        $this->assertTrue($result['summary']['has_detected_anomalies']);
        $this->assertSame($reservation->id, $finding['evidence']['reservation_id']);
        $this->assertSame(CustomerClassPassReservationStatus::Released->value, $finding['evidence']['reservation_status']);
    }

    public function test_it_does_not_reveal_a_customer_from_another_account(): void
    {
        $account = Account::factory()->create();
        $otherCustomer = Customer::factory()->for(Account::factory())->create();

        $result = app(CustomerBookingLedgerInvestigation::class)->investigate($account, $otherCustomer->id);

        $this->assertSame('not_found', $result['status']);
        $this->assertArrayNotHasKey('customer', $result);
    }

    public function test_customer_search_is_tenant_scoped_and_masks_disambiguation_contacts(): void
    {
        $account = Account::factory()->create();
        Customer::factory()->for($account)->create([
            'name' => 'Anna Test',
            'phone' => '+380671112233',
            'email' => 'anna@example.com',
        ]);
        Customer::factory()->for($account)->create([
            'name' => 'Anna Other',
            'phone' => '+380679998877',
            'email' => 'other@example.com',
        ]);
        Customer::factory()->for(Account::factory())->create([
            'name' => 'Anna Outside',
            'phone' => '+380670000000',
        ]);

        $result = app(CustomerInvestigationSearch::class)->search($account, 'Anna');

        $this->assertSame('ambiguous', $result['status']);
        $this->assertCount(2, $result['matches']);
        $annaTest = collect($result['matches'])->firstWhere('name', 'Anna Test');
        $this->assertStringEndsWith('2233', $annaTest['phone_masked']);
        $this->assertStringNotContainsString('+380671112233', $annaTest['phone_masked']);
        $this->assertSame('a***@example.com', $annaTest['email_masked']);
        $this->assertNotContains('Anna Outside', array_column($result['matches'], 'name'));
    }

    private function scheduledClass(
        Account $account,
        Location $location,
        ClassType $classType,
        string $localStartsAt,
    ): ScheduledClass {
        $startsAt = Carbon::parse($localStartsAt, 'Europe/Kyiv')->utc();

        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($classType)
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);
    }

    private function booking(
        Account $account,
        Customer $customer,
        ScheduledClass $scheduledClass,
        string $localCreatedAt,
        string $actorRole,
    ): ClassBooking {
        return ClassBooking::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->for($scheduledClass)
            ->create([
                'status' => ClassBookingStatus::Booked->value,
                'booked_by_user_id' => null,
                'booked_by_actor_name' => $actorRole === 'customer' ? $customer->name : 'Studio owner',
                'booked_by_actor_role' => $actorRole,
                'created_at' => Carbon::parse($localCreatedAt, 'Europe/Kyiv')->utc(),
            ]);
    }

    private function usedReservation(
        Account $account,
        CustomerClassPass $classPass,
        ClassBooking $booking,
        ScheduledClass $scheduledClass,
    ): CustomerClassPassReservation {
        return CustomerClassPassReservation::factory()
            ->for($account)
            ->for($classPass)
            ->for($booking)
            ->for($scheduledClass)
            ->create([
                'status' => CustomerClassPassReservationStatus::Used->value,
                'reserved_at' => $booking->created_at,
                'used_at' => $scheduledClass->starts_at,
                'created_at' => $booking->created_at,
            ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function bookingResult(array $result, int $bookingId): array
    {
        return collect($result['bookings'])->firstWhere('booking_id', $bookingId);
    }
}
