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
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Models\ScheduledClass;
use App\Models\User;
use App\Support\CustomerBookingLedgerInvestigation;
use App\Support\CustomerInvestigationSearch;
use App\Support\TrialClassPassEligibility;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerBookingLedgerInvestigationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_includes_outstanding_passes_outside_the_period_and_excludes_cancelled_paid_and_other_tenant_passes(): void
    {
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $customer = Customer::factory()->for($account)->create();
        $classPassPlan = ClassPassPlan::factory()->for($account)->create([
            'price_cents' => 100000,
        ]);
        CustomerClassPass::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->for($classPassPlan)
            ->create([
                'code' => 'USED-DEBT',
                'price_cents' => 100000,
                'paid_amount_cents' => 0,
                'is_paid' => false,
                'status' => CustomerClassPassStatus::UsedUp->value,
                'is_active' => false,
                'purchased_at' => Carbon::parse('2025-01-01 10:00:00', 'Europe/Kyiv')->utc(),
                'closed_at' => Carbon::parse('2025-01-15 10:00:00', 'Europe/Kyiv')->utc(),
            ]);
        CustomerClassPass::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->for($classPassPlan)
            ->create([
                'code' => 'EXPIRED-PARTIAL',
                'price_cents' => 100000,
                'paid_amount_cents' => 40000,
                'is_paid' => false,
                'status' => CustomerClassPassStatus::Expired->value,
                'is_active' => false,
                'purchased_at' => Carbon::parse('2025-02-01 10:00:00', 'Europe/Kyiv')->utc(),
                'closed_at' => Carbon::parse('2025-03-01 10:00:00', 'Europe/Kyiv')->utc(),
            ]);
        CustomerClassPass::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->for($classPassPlan)
            ->create([
                'code' => 'CANCELLED-DEBT',
                'price_cents' => 100000,
                'paid_amount_cents' => 0,
                'is_paid' => false,
                'status' => CustomerClassPassStatus::Cancelled->value,
                'is_active' => false,
                'purchased_at' => Carbon::parse('2025-03-01 10:00:00', 'Europe/Kyiv')->utc(),
                'closed_at' => Carbon::parse('2025-03-02 10:00:00', 'Europe/Kyiv')->utc(),
            ]);
        CustomerClassPass::factory()
            ->for($account)
            ->for($customer, 'customer')
            ->for($classPassPlan)
            ->create([
                'code' => 'USED-PAID',
                'price_cents' => 100000,
                'paid_amount_cents' => 100000,
                'is_paid' => true,
                'status' => CustomerClassPassStatus::UsedUp->value,
                'is_active' => false,
                'purchased_at' => Carbon::parse('2025-04-01 10:00:00', 'Europe/Kyiv')->utc(),
                'closed_at' => Carbon::parse('2025-04-15 10:00:00', 'Europe/Kyiv')->utc(),
            ]);
        $otherAccount = Account::factory()->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create();
        $otherPlan = ClassPassPlan::factory()->for($otherAccount)->create([
            'price_cents' => 100000,
        ]);
        CustomerClassPass::factory()
            ->for($otherAccount)
            ->for($otherCustomer, 'customer')
            ->for($otherPlan)
            ->create([
                'code' => 'OTHER-DEBT',
                'price_cents' => 100000,
                'paid_amount_cents' => 0,
                'is_paid' => false,
                'status' => CustomerClassPassStatus::UsedUp->value,
                'is_active' => false,
                'purchased_at' => Carbon::parse('2025-01-01 10:00:00', 'Europe/Kyiv')->utc(),
            ]);

        $result = app(CustomerBookingLedgerInvestigation::class)->investigate(
            $account,
            $customer->id,
            '2026-07-01',
            '2026-07-31',
        );

        $usedDebt = collect($result['passes'])->firstWhere('code', 'USED-DEBT');
        $expiredPartial = collect($result['passes'])->firstWhere('code', 'EXPIRED-PARTIAL');

        $this->assertSame(1, $result['summary']['outstanding_unpaid_passes_count']);
        $this->assertSame(1, $result['summary']['outstanding_partial_passes_count']);
        $this->assertSame(['USED-DEBT', 'EXPIRED-PARTIAL'], array_column($result['passes'], 'code'));
        $this->assertSame('unpaid', $usedDebt['payment_status']);
        $this->assertTrue($usedDebt['has_outstanding_balance']);
        $this->assertSame(100000, $usedDebt['price_cents']);
        $this->assertSame(0, $usedDebt['paid_amount_cents']);
        $this->assertSame(100000, $usedDebt['remaining_payment_cents']);
        $this->assertSame('partial', $expiredPartial['payment_status']);
        $this->assertTrue($expiredPartial['has_outstanding_balance']);
        $this->assertSame(40000, $expiredPartial['paid_amount_cents']);
        $this->assertSame(60000, $expiredPartial['remaining_payment_cents']);
        $this->assertNotContains('CANCELLED-DEBT', array_column($result['passes'], 'code'));
        $this->assertNotContains('USED-PAID', array_column($result['passes'], 'code'));
        $this->assertNotContains('OTHER-DEBT', array_column($result['passes'], 'code'));
    }

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
                    'reason' => 'Corrected imported session balance.',
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
            $this->assertSame('Corrected imported session balance.', $result['adjustments'][0]['reason']);
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

    public function test_shared_trial_eligibility_preserves_manual_and_online_issuance_rules(): void
    {
        $account = Account::factory()->create();
        $eligibility = app(TrialClassPassEligibility::class);
        $scheduledClass = ScheduledClass::factory()->for($account)->create();

        $customerWithoutBookings = Customer::factory()->for($account)->create();
        $this->assertSame(
            [
                'status' => 'eligible',
                'reason_codes' => ['no_existing_bookings'],
                'counted_bookings_count' => 0,
                'active_reservations_count' => 0,
            ],
            $eligibility->evaluate($account, $customerWithoutBookings),
        );

        $customerWithOneBooking = Customer::factory()->for($account)->create();
        $singleBooking = ClassBooking::factory()
            ->for($account)
            ->for($customerWithOneBooking, 'customer')
            ->for($scheduledClass)
            ->create();

        $this->assertSame(
            'single_unreserved_booking_manual_exception',
            $eligibility->evaluate($account, $customerWithOneBooking)['reason_codes'][0],
        );
        $this->assertSame(
            'existing_booking_non_manual',
            $eligibility->evaluate(
                $account,
                $customerWithOneBooking,
                TrialClassPassEligibility::SourceOnlinePayment,
            )['reason_codes'][0],
        );

        $classPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customerWithOneBooking, 'customer')
            ->create();
        CustomerClassPassReservation::factory()
            ->for($account)
            ->for($classPass)
            ->for($singleBooking)
            ->for($scheduledClass)
            ->create([
                'status' => CustomerClassPassReservationStatus::Used->value,
            ]);

        $reservedResult = $eligibility->evaluate($account, $customerWithOneBooking);
        $this->assertSame('ineligible', $reservedResult['status']);
        $this->assertSame(['active_reservation_exists'], $reservedResult['reason_codes']);
        $this->assertSame(1, $reservedResult['active_reservations_count']);

        $customerWithMixedBookings = Customer::factory()->for($account)->create();
        ClassBooking::factory()
            ->for($account)
            ->for($customerWithMixedBookings, 'customer')
            ->for(ScheduledClass::factory()->for($account))
            ->create(['status' => ClassBookingStatus::Cancelled->value]);
        ClassBooking::factory()
            ->for($account)
            ->for($customerWithMixedBookings, 'customer')
            ->for(ScheduledClass::factory()->for($account))
            ->create([
                'status' => ClassBookingStatus::Attended->value,
                'attended_at' => now()->subDay(),
            ]);
        ClassBooking::factory()
            ->for($account)
            ->for($customerWithMixedBookings, 'customer')
            ->for(ScheduledClass::factory()->for($account)->state([
                'starts_at' => now()->addWeek(),
                'ends_at' => now()->addWeek()->addHour(),
            ]))
            ->create();

        $mixedResult = $eligibility->evaluate($account, $customerWithMixedBookings);
        $this->assertSame('ineligible', $mixedResult['status']);
        $this->assertSame(['multiple_existing_bookings'], $mixedResult['reason_codes']);
        $this->assertSame(3, $mixedResult['counted_bookings_count']);
    }

    public function test_trial_eligibility_uses_all_time_history_outside_the_detailed_timeline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Europe/Kyiv'));

        try {
            $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
            $customer = Customer::factory()->for($account)->create(['name' => 'Anna Example']);
            ClassPassPlan::factory()->for($account)->create([
                'name' => 'Пробне заняття',
                'is_trial' => true,
                'price_cents' => 25000,
                'currency' => 'UAH',
            ]);
            $oldScheduledClass = ScheduledClass::factory()->for($account)->create([
                'starts_at' => Carbon::parse('2026-01-10 19:00:00', 'Europe/Kyiv')->utc(),
                'ends_at' => Carbon::parse('2026-01-10 20:00:00', 'Europe/Kyiv')->utc(),
            ]);
            $oldBooking = ClassBooking::factory()
                ->for($account)
                ->for($customer, 'customer')
                ->for($oldScheduledClass)
                ->create([
                    'status' => ClassBookingStatus::Attended->value,
                    'attended_at' => Carbon::parse('2026-01-10 20:02:00', 'Europe/Kyiv')->utc(),
                    'created_at' => Carbon::parse('2026-01-09 10:00:00', 'Europe/Kyiv')->utc(),
                ]);
            $currentScheduledClass = ScheduledClass::factory()->for($account)->create([
                'starts_at' => Carbon::parse('2026-07-28 19:00:00', 'Europe/Kyiv')->utc(),
                'ends_at' => Carbon::parse('2026-07-28 20:00:00', 'Europe/Kyiv')->utc(),
            ]);
            ClassBooking::factory()
                ->for($account)
                ->for($customer, 'customer')
                ->for($currentScheduledClass)
                ->create([
                    'status' => ClassBookingStatus::Cancelled->value,
                    'created_at' => Carbon::parse('2026-07-27 10:00:00', 'Europe/Kyiv')->utc(),
                ]);

            $result = app(CustomerBookingLedgerInvestigation::class)->investigate(
                $account,
                $customer->id,
                '2026-07-20',
                '2026-07-31',
            );

            $this->assertSame(1, $result['summary']['bookings_count']);
            $this->assertSame(2, $result['customer_history_summary']['counted_bookings_count']);
            $this->assertSame(1, $result['customer_history_summary']['prior_attended_bookings_count']);
            $this->assertSame(
                $oldBooking->id,
                $result['customer_history_summary']['earliest_prior_attended_booking']['booking_id'],
            );
            $this->assertSame('ineligible', $result['trial_eligibility']['status']);
            $this->assertSame(['multiple_existing_bookings'], $result['trial_eligibility']['reason_codes']);
            $this->assertSame(2, $result['trial_eligibility']['supporting_bookings']['total']);
            $this->assertSame(25000, $result['trial_eligibility']['trial_plans']['items'][0]['price_cents']);
            $this->assertFalse($result['trial_eligibility']['trial_plans']['truncated']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_as_of_excludes_later_bookings_and_applies_corrections_at_their_recorded_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Europe/Kyiv'));

        try {
            $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
            $customer = Customer::factory()->for($account)->create();
            ClassPassPlan::factory()->for($account)->create(['is_trial' => true]);
            $firstBooking = ClassBooking::factory()
                ->for($account)
                ->for($customer, 'customer')
                ->for(ScheduledClass::factory()->for($account))
                ->create([
                    'created_at' => Carbon::parse('2026-07-01 10:00:00', 'Europe/Kyiv')->utc(),
                    'corrected_removed_at' => Carbon::parse('2026-07-20 10:00:00', 'Europe/Kyiv')->utc(),
                ]);
            ClassBooking::factory()
                ->for($account)
                ->for($customer, 'customer')
                ->for(ScheduledClass::factory()->for($account))
                ->create([
                    'created_at' => Carbon::parse('2026-07-15 10:00:00', 'Europe/Kyiv')->utc(),
                ]);

            $beforeLaterBooking = app(CustomerBookingLedgerInvestigation::class)->investigate(
                $account,
                $customer->id,
                asOf: '2026-07-10T12:00:00+03:00',
            );
            $beforeCorrection = app(CustomerBookingLedgerInvestigation::class)->investigate(
                $account,
                $customer->id,
                asOf: '2026-07-18T12:00:00+03:00',
            );
            $afterCorrection = app(CustomerBookingLedgerInvestigation::class)->investigate(
                $account,
                $customer->id,
                asOf: '2026-07-25T12:00:00+03:00',
            );

            $this->assertSame(1, $beforeLaterBooking['customer_history_summary']['counted_bookings_count']);
            $this->assertSame(
                $firstBooking->id,
                $beforeLaterBooking['customer_history_summary']['supporting_bookings']['items'][0]['booking_id'],
            );
            $this->assertSame(2, $beforeCorrection['customer_history_summary']['counted_bookings_count']);
            $this->assertSame(['multiple_existing_bookings'], $beforeCorrection['trial_eligibility']['reason_codes']);
            $this->assertSame(1, $afterCorrection['customer_history_summary']['counted_bookings_count']);
            $this->assertSame(
                ['single_unreserved_booking_manual_exception'],
                $afterCorrection['trial_eligibility']['reason_codes'],
            );
            $this->assertFalse($afterCorrection['trial_eligibility']['historical_reconstruction']['reservation_history_complete']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_charmpole_like_trial_investigation_returns_deterministic_history_and_outstanding_balance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Europe/Kyiv'));

        try {
            $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
            $owner = User::factory()->create();
            $account->addOwner($owner);
            $customer = Customer::factory()->for($account)->create(['name' => 'Anna Example']);
            ClassPassPlan::factory()->for($account)->create([
                'name' => 'First time',
                'is_trial' => true,
                'price_cents' => 25000,
                'currency' => 'UAH',
            ]);
            $regularPlan = ClassPassPlan::factory()->for($account)->create([
                'name' => 'Single visit',
                'is_trial' => false,
                'price_cents' => 45000,
                'currency' => 'UAH',
                'sessions_count' => 1,
            ]);
            $bookingDefinitions = [
                ['2026-07-23 18:00:00', ClassBookingStatus::Cancelled, null],
                ['2026-07-23 19:00:00', ClassBookingStatus::Attended, '2026-07-23 20:02:00'],
                ['2026-07-30 19:00:00', ClassBookingStatus::Booked, null],
                ['2026-07-27 20:00:00', ClassBookingStatus::Cancelled, null],
                ['2026-07-28 19:00:00', ClassBookingStatus::Attended, '2026-07-28 20:03:00'],
            ];
            $bookings = collect($bookingDefinitions)
                ->map(function (array $definition, int $index) use ($account, $customer): ClassBooking {
                    [$startsAt, $status, $attendedAt] = $definition;
                    $scheduledClass = ScheduledClass::factory()->for($account)->create([
                        'title' => 'Pole Dance',
                        'starts_at' => Carbon::parse($startsAt, 'Europe/Kyiv')->utc(),
                        'ends_at' => Carbon::parse($startsAt, 'Europe/Kyiv')->addHour()->utc(),
                    ]);

                    return ClassBooking::factory()
                        ->for($account)
                        ->for($customer, 'customer')
                        ->for($scheduledClass)
                        ->create([
                            'status' => $status->value,
                            'attended_at' => $attendedAt
                                ? Carbon::parse($attendedAt, 'Europe/Kyiv')->utc()
                                : null,
                            'created_at' => Carbon::parse(
                                sprintf('2026-07-%02d 10:00:00', 20 + $index),
                                'Europe/Kyiv',
                            )->utc(),
                        ]);
                });
            $onlinePass = CustomerClassPass::factory()
                ->for($account)
                ->for($customer, 'customer')
                ->for($regularPlan)
                ->create([
                    'code' => 'ONLINE-450',
                    'price_cents' => 45000,
                    'paid_amount_cents' => 45000,
                    'is_paid' => true,
                    'sessions_count' => 1,
                    'used_sessions_count' => 1,
                    'source' => 'online_payment',
                    'purchased_at' => Carbon::parse('2026-07-28 20:07:00', 'Europe/Kyiv')->utc(),
                ]);
            $manualPass = CustomerClassPass::factory()
                ->for($account)
                ->for($customer, 'customer')
                ->for($regularPlan)
                ->create([
                    'code' => 'MANUAL-450',
                    'price_cents' => 45000,
                    'paid_amount_cents' => 25000,
                    'is_paid' => false,
                    'sessions_count' => 1,
                    'used_sessions_count' => 1,
                    'source' => 'manual',
                    'purchased_at' => Carbon::parse('2026-07-29 00:20:00', 'Europe/Kyiv')->utc(),
                ]);
            CustomerClassPassReservation::factory()
                ->for($account)
                ->for($onlinePass)
                ->for($bookings[1])
                ->for($bookings[1]->scheduledClass)
                ->create([
                    'status' => CustomerClassPassReservationStatus::Used->value,
                    'reserved_at' => Carbon::parse('2026-07-28 20:07:00', 'Europe/Kyiv')->utc(),
                    'used_at' => Carbon::parse('2026-07-23 20:02:00', 'Europe/Kyiv')->utc(),
                ]);
            CustomerClassPassReservation::factory()
                ->for($account)
                ->for($manualPass)
                ->for($bookings[4])
                ->for($bookings[4]->scheduledClass)
                ->create([
                    'status' => CustomerClassPassReservationStatus::Used->value,
                    'reserved_at' => Carbon::parse('2026-07-29 00:20:00', 'Europe/Kyiv')->utc(),
                    'used_at' => Carbon::parse('2026-07-28 20:03:00', 'Europe/Kyiv')->utc(),
                ]);

            $result = app(CustomerBookingLedgerInvestigation::class)->investigate(
                $account,
                $customer->id,
                '2026-07-20',
                '2026-07-31',
                '2026-07-28T20:05:00+03:00',
                TrialClassPassEligibility::SourceManual,
                $owner,
            );

            $this->assertSame(5, $result['customer_history_summary']['counted_bookings_count']);
            $this->assertSame(
                $bookings[1]->id,
                $result['customer_history_summary']['earliest_prior_attended_booking']['booking_id'],
            );
            $this->assertSame('ineligible', $result['trial_eligibility']['status']);
            $this->assertSame(['multiple_existing_bookings'], $result['trial_eligibility']['reason_codes']);
            $this->assertSame(25000, $result['trial_eligibility']['trial_plans']['items'][0]['price_cents']);
            $this->assertSame('available', $result['manual_override']['status']);
            $this->assertTrue($result['manual_override']['available']);
            $this->assertTrue($result['manual_override']['customer_qualifies']);
            $this->assertSame(0, $result['manual_override']['class_pass_history_count']);
            $this->assertSame(0, $result['manual_override']['successful_payments_count']);
            $this->assertSame(['manual_override_available'], $result['manual_override']['reason_codes']);
            $this->assertTrue($result['manual_override']['human_exception']);
            $this->assertSame(1, $result['summary']['outstanding_partial_passes_count']);
            $partialPass = collect($result['passes'])->firstWhere('code', 'MANUAL-450');
            $this->assertSame(20000, $partialPass['remaining_payment_cents']);
            $futureBooking = $this->bookingResult($result, $bookings[2]->id);
            $this->assertNull($futureBooking['reservation']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_override_history_checks_respect_as_of_and_block_current_regular_customer_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Europe/Kyiv'));

        try {
            $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
            $owner = User::factory()->create();
            $account->addOwner($owner);
            $customer = Customer::factory()->for($account)->create();
            ClassPassPlan::factory()->for($account)->create(['is_trial' => true]);
            $regularPlan = ClassPassPlan::factory()->for($account)->create(['is_trial' => false]);

            foreach (['2026-07-23 19:00:00', '2026-07-28 19:00:00'] as $startsAt) {
                $scheduledClass = ScheduledClass::factory()->for($account)->create([
                    'starts_at' => Carbon::parse($startsAt, 'Europe/Kyiv')->utc(),
                    'ends_at' => Carbon::parse($startsAt, 'Europe/Kyiv')->addHour()->utc(),
                ]);
                ClassBooking::factory()
                    ->for($account)
                    ->for($customer)
                    ->for($scheduledClass)
                    ->create([
                        'created_at' => Carbon::parse('2026-07-20 10:00:00', 'Europe/Kyiv')->utc(),
                    ]);
            }

            $pass = CustomerClassPass::factory()
                ->for($account)
                ->for($customer)
                ->for($regularPlan)
                ->create([
                    'created_at' => Carbon::parse('2026-07-29 09:00:00', 'Europe/Kyiv')->utc(),
                ]);
            CustomerPurchase::factory()
                ->for($account)
                ->for($customer)
                ->for($regularPlan, 'classPassPlan')
                ->for($pass, 'customerClassPass')
                ->create([
                    'status' => 'payment_paid',
                    'paid_at' => Carbon::parse('2026-07-29 09:05:00', 'Europe/Kyiv')->utc(),
                    'created_at' => Carbon::parse('2026-07-29 09:00:00', 'Europe/Kyiv')->utc(),
                ]);

            $historical = app(CustomerBookingLedgerInvestigation::class)->investigate(
                $account,
                $customer->id,
                asOf: '2026-07-28T20:05:00+03:00',
                actor: $owner,
            );
            $current = app(CustomerBookingLedgerInvestigation::class)->investigate(
                $account,
                $customer->id,
                actor: $owner,
            );

            $this->assertSame('ineligible', $historical['trial_eligibility']['status']);
            $this->assertTrue($historical['manual_override']['available']);
            $this->assertSame(0, $historical['manual_override']['class_pass_history_count']);
            $this->assertSame(0, $historical['manual_override']['successful_payments_count']);
            $this->assertFalse($current['manual_override']['available']);
            $this->assertSame(1, $current['manual_override']['class_pass_history_count']);
            $this->assertSame(1, $current['manual_override']['successful_payments_count']);
            $this->assertContains('existing_class_pass_history', $current['manual_override']['reason_codes']);
            $this->assertContains('successful_payment_history', $current['manual_override']['reason_codes']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_trial_plan_candidates_are_tenant_scoped_bounded_and_report_missing_configuration(): void
    {
        $account = Account::factory()->create();
        $customer = Customer::factory()->for($account)->create();
        ClassPassPlan::factory()
            ->count(21)
            ->for($account)
            ->create(['is_trial' => true]);
        ClassPassPlan::factory()
            ->for(Account::factory())
            ->create([
                'name' => 'Other tenant trial',
                'is_trial' => true,
            ]);

        $result = app(CustomerBookingLedgerInvestigation::class)->investigate($account, $customer->id);

        $this->assertSame('eligible', $result['trial_eligibility']['status']);
        $this->assertSame(20, $result['trial_eligibility']['trial_plans']['returned']);
        $this->assertSame(21, $result['trial_eligibility']['trial_plans']['total']);
        $this->assertTrue($result['trial_eligibility']['trial_plans']['truncated']);
        $this->assertNotContains(
            'Other tenant trial',
            array_column($result['trial_eligibility']['trial_plans']['items'], 'name'),
        );

        $accountWithoutTrial = Account::factory()->create();
        $customerWithoutTrial = Customer::factory()->for($accountWithoutTrial)->create();
        $notConfigured = app(CustomerBookingLedgerInvestigation::class)->investigate(
            $accountWithoutTrial,
            $customerWithoutTrial->id,
        );

        $this->assertSame('not_configured', $notConfigured['trial_eligibility']['status']);
        $this->assertSame(['no_trial_plan_configured'], $notConfigured['trial_eligibility']['reason_codes']);
        $this->assertSame(0, $notConfigured['trial_eligibility']['trial_plans']['total']);
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
