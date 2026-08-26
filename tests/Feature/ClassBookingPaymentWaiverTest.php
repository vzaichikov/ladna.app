<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\RecordManualClassBookingPayment;
use App\Actions\ReserveCustomerClassPassForBooking;
use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassBookingPaymentWaiver;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPassReservation;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\StudioCashEntry;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClassBookingPaymentWaiverTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_waive_and_restore_direct_rental_without_changing_financial_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00', 'UTC'));

        [$owner, $account, $location, $room] = $this->studio();
        $rentalType = $this->classType($account, ScheduleKind::RoomRental, 'Room rental');
        $scheduledClass = $this->scheduledClass($account, $location, $room, $rentalType, 'Legacy direct rent');
        $customer = Customer::factory()->for($account)->create(['name' => 'Legacy Client']);
        $booking = $this->booking($account, $scheduledClass, $customer, skipClassPassReservation: true);
        $purchasesBefore = CustomerPurchase::query()->count();
        $cashEntriesBefore = StudioCashEntry::query()->count();
        $reportUrl = route('dashboard.accounts.reports.unpaid-class-payments', ['account' => $account, 'page' => 1]);

        $this->actingAs($owner)
            ->get($reportUrl)
            ->assertOk()
            ->assertSee('Legacy Client')
            ->assertSee(__('app.waived_class_booking_payments'))
            ->assertSee('data-confirm-action', false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.bookings.payment-waivers.store', [$account, $booking]), [
                'reason' => 'Legacy payment data had not been entered yet.',
                'return_to' => $reportUrl,
            ])
            ->assertRedirect(parse_url($reportUrl, PHP_URL_PATH).'?page=1')
            ->assertSessionHas('status', __('app.class_booking_payment_waived'));

        $waiver = ClassBookingPaymentWaiver::query()->sole();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.bookings.payment-waivers.store', [$account, $booking]), [
                'reason' => 'Duplicate submission should be rejected.',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('class_booking_payment_waivers', 1);

        $this->assertSame($account->id, $waiver->account_id);
        $this->assertSame($booking->id, $waiver->class_booking_id);
        $this->assertSame(ClassBooking::ManualPaymentDueRoomRental, $waiver->payment_due_kind);
        $this->assertNull($waiver->amount_cents);
        $this->assertSame('Legacy Client', $waiver->customer_name);
        $this->assertSame('Legacy direct rent', $waiver->scheduled_class_title);
        $this->assertSame($owner->name, $waiver->waived_by_actor_name);
        $this->assertSame($purchasesBefore, CustomerPurchase::query()->count());
        $this->assertSame($cashEntriesBefore, StudioCashEntry::query()->count());

        $this->actingAs($owner)
            ->get($reportUrl)
            ->assertOk()
            ->assertDontSee('Legacy Client')
            ->assertSee(__('app.no_unpaid_class_booking_payments'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSee('Legacy Client')
            ->assertDontSee(__('app.unpaid_class_booking_payment_alert'));

        $historyUrl = route('dashboard.accounts.reports.unpaid-class-payments.waived', [
            'account' => $account,
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->get($historyUrl)
            ->assertOk()
            ->assertSee('Legacy Client')
            ->assertSee('Legacy payment data had not been entered yet.')
            ->assertSee(__('app.unwaive_payment'));

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.bookings.payment.store', [$account, $booking]), [
                'amount' => '300.00',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame($purchasesBefore, CustomerPurchase::query()->count());
        $this->assertSame($cashEntriesBefore, StudioCashEntry::query()->count());

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.booking-payment-waivers.unwaive', [$account, $waiver]), [
                'reason' => 'This was waived by mistake.',
                'return_to' => $historyUrl,
            ])
            ->assertRedirect(parse_url($historyUrl, PHP_URL_PATH).'?status=active')
            ->assertSessionHas('status', __('app.class_booking_payment_unwaived'));

        $waiver->refresh();
        $this->assertNotNull($waiver->unwaived_at);
        $this->assertSame('This was waived by mistake.', $waiver->unwaive_reason);
        $this->assertSame($owner->name, $waiver->unwaived_by_actor_name);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.booking-payment-waivers.unwaive', [$account, $waiver]), [
                'reason' => 'Duplicate restoration should be rejected.',
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($owner)
            ->get($reportUrl)
            ->assertOk()
            ->assertSee('Legacy Client');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.bookings.payment.store', [$account, $booking]), [
                'amount' => '300.00',
                'return_to' => $reportUrl,
            ])
            ->assertRedirect();

        $this->assertSame($purchasesBefore + 1, CustomerPurchase::query()->count());
        $this->assertSame($cashEntriesBefore + 1, StudioCashEntry::query()->count());
    }

    public function test_any_time_addon_waiver_snapshots_amount_and_preserves_pass_and_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00', 'UTC'));

        [$owner, $account, $location, $room] = $this->studio();
        $groupType = $this->classType($account, ScheduleKind::GroupClass, 'Pole group');
        $scheduledClass = $this->scheduledClass($account, $location, $room, $groupType, 'Evening Pole');
        $customer = Customer::factory()->for($account)->create(['name' => 'Add-on Client']);
        [$booking, $reservation] = $this->anyTimeBooking($account, $scheduledClass, $groupType, $customer);
        $classPass = $reservation->customerClassPass;
        $reservationStateBefore = $reservation->getAttributes();
        $classPassStateBefore = $classPass->getAttributes();
        $purchasesBefore = CustomerPurchase::query()->count();
        $cashEntriesBefore = StudioCashEntry::query()->count();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.bookings.payment-waivers.store', [$account, $booking]), [
                'reason' => 'The studio intentionally forgave this add-on.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $waiver = ClassBookingPaymentWaiver::query()->sole();

        $this->assertSame(ClassBooking::ManualPaymentDueAnyTimeAddon, $waiver->payment_due_kind);
        $this->assertSame(4500, $waiver->amount_cents);
        $this->assertSame($classPass->id, $waiver->customer_class_pass_id);
        $this->assertSame($classPass->code, $waiver->customer_class_pass_code);
        $this->assertSame($classPass->currency, $waiver->currency);
        $this->assertSame($purchasesBefore, CustomerPurchase::query()->count());
        $this->assertSame($cashEntriesBefore, StudioCashEntry::query()->count());
        $this->assertEquals($reservationStateBefore, $reservation->fresh()->getAttributes());
        $this->assertEquals($classPassStateBefore, $classPass->fresh()->getAttributes());
        $this->assertNull($booking->fresh()->manualCashPayment);
        $this->assertNull($booking->fresh()->manualCashPaymentDueKind());
    }

    public function test_only_actual_studio_owner_can_view_or_change_waivers(): void
    {
        [$owner, $account, $location, $room] = $this->studio();
        $staff = User::factory()->create();
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account->users()->attach($staff, ['role' => AccountRole::Receptionist->value]);
        $rentalType = $this->classType($account, ScheduleKind::RoomRental, 'Room rental');
        $scheduledClass = $this->scheduledClass($account, $location, $room, $rentalType, 'Owner-only rent');
        $customer = Customer::factory()->for($account)->create();
        $booking = $this->booking($account, $scheduledClass, $customer, skipClassPassReservation: true);

        foreach ([$staff, $platformAdmin] as $unauthorizedUser) {
            $this->actingAs($unauthorizedUser)
                ->get(route('dashboard.accounts.reports.unpaid-class-payments.waived', $account))
                ->assertForbidden();

            $this->actingAs($unauthorizedUser)
                ->post(route('dashboard.accounts.bookings.payment-waivers.store', [$account, $booking]), [
                    'reason' => 'Should not be accepted.',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('class_booking_payment_waivers', 0);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.bookings.payment-waivers.store', [$account, $booking]), [
                'reason' => 'Owner-approved waiver.',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('class_booking_payment_waivers', 1);
        $waiver = ClassBookingPaymentWaiver::query()->sole();

        foreach ([$staff, $platformAdmin] as $unauthorizedUser) {
            $this->actingAs($unauthorizedUser)
                ->patch(route('dashboard.accounts.booking-payment-waivers.unwaive', [$account, $waiver]), [
                    'reason' => 'Should not be accepted.',
                ])
                ->assertForbidden();
        }

        $this->assertNull($waiver->fresh()->unwaived_at);
    }

    public function test_waiver_actions_are_scoped_to_the_exact_account(): void
    {
        [$firstOwner, $firstAccount] = $this->studio();
        [$secondOwner, $secondAccount, $secondLocation, $secondRoom] = $this->studio();
        $rentalType = $this->classType($secondAccount, ScheduleKind::RoomRental, 'Room rental');
        $scheduledClass = $this->scheduledClass($secondAccount, $secondLocation, $secondRoom, $rentalType, 'Other studio rent');
        $customer = Customer::factory()->for($secondAccount)->create();
        $booking = $this->booking($secondAccount, $scheduledClass, $customer, skipClassPassReservation: true);

        $this->actingAs($firstOwner)
            ->post(route('dashboard.accounts.bookings.payment-waivers.store', [$firstAccount, $booking]), [
                'reason' => 'Cross-account attempt.',
            ])
            ->assertNotFound();

        $this->actingAs($secondOwner)
            ->post(route('dashboard.accounts.bookings.payment-waivers.store', [$secondAccount, $booking]), [
                'reason' => 'Valid second-account waiver.',
            ])
            ->assertRedirect();

        $waiver = ClassBookingPaymentWaiver::query()->sole();

        $this->actingAs($firstOwner)
            ->patch(route('dashboard.accounts.booking-payment-waivers.unwaive', [$firstAccount, $waiver]), [
                'reason' => 'Cross-account restoration attempt.',
            ])
            ->assertNotFound();

        $this->assertNull($waiver->fresh()->unwaived_at);
    }

    public function test_paid_and_cancelled_bookings_cannot_be_waived(): void
    {
        [$owner, $account, $location, $room] = $this->studio();
        $rentalType = $this->classType($account, ScheduleKind::RoomRental, 'Room rental');
        $paidClass = $this->scheduledClass($account, $location, $room, $rentalType, 'Paid rent');
        $cancelledClass = $this->scheduledClass($account, $location, $room, $rentalType, 'Cancelled rent');
        $paidBooking = $this->booking(
            $account,
            $paidClass,
            Customer::factory()->for($account)->create(),
            skipClassPassReservation: true,
        );
        $cancelledBooking = $this->booking(
            $account,
            $cancelledClass,
            Customer::factory()->for($account)->create(),
            skipClassPassReservation: true,
        );
        $cancelledBooking->update(['status' => ClassBookingStatus::Cancelled]);
        app(RecordManualClassBookingPayment::class)->execute($account, $paidBooking, 30000, $owner);

        foreach ([$paidBooking, $cancelledBooking] as $booking) {
            $this->actingAs($owner)
                ->post(route('dashboard.accounts.bookings.payment-waivers.store', [$account, $booking]), [
                    'reason' => 'This booking is not due.',
                ])
                ->assertSessionHasErrors('reason');
        }

        $this->assertDatabaseCount('class_booking_payment_waivers', 0);
    }

    public function test_history_is_paginated_filterable_and_survives_booking_deletion(): void
    {
        [$owner, $account, $location, $room] = $this->studio();
        $rentalType = $this->classType($account, ScheduleKind::RoomRental, 'Room rental');
        $scheduledClass = $this->scheduledClass($account, $location, $room, $rentalType, 'Deleted rent');
        $customer = Customer::factory()->for($account)->create(['name' => 'Deleted Booking Client']);
        $booking = $this->booking($account, $scheduledClass, $customer, skipClassPassReservation: true);

        $this->actingAs($owner)->post(
            route('dashboard.accounts.bookings.payment-waivers.store', [$account, $booking]),
            ['reason' => 'Keep this history.'],
        )->assertRedirect();

        $booking->delete();
        $preservedWaiver = ClassBookingPaymentWaiver::query()->sole();
        $this->assertNull($preservedWaiver->class_booking_id);

        ClassBookingPaymentWaiver::factory()->count(25)->for($account)->create();
        ClassBookingPaymentWaiver::factory()->unwaived()->for($account)->create([
            'customer_name' => 'Restored History Client',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.unpaid-class-payments.waived', $account))
            ->assertOk()
            ->assertSee('Deleted Booking Client')
            ->assertSee(__('app.class_booking_payment_unwaive_unavailable'))
            ->assertSee('page=2', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.unpaid-class-payments.waived', [
                'account' => $account,
                'status' => 'unwaived',
            ]))
            ->assertOk()
            ->assertSee('Restored History Client')
            ->assertDontSee('Deleted Booking Client');
    }

    /**
     * @return array{0: User, 1: Account, 2: Location, 3: Room}
     */
    private function studio(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'default_currency' => 'UAH',
            'timezone' => 'UTC',
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create([
            'name' => 'Main desk',
            'timezone' => 'UTC',
        ]);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Main Hall']);

        return [$owner, $account, $location, $room];
    }

    private function classType(Account $account, ScheduleKind $scheduleKind, string $name): ClassType
    {
        return ClassType::factory()->for($account)->create([
            'name' => $name,
            'schedule_kind' => $scheduleKind->value,
        ]);
    }

    private function scheduledClass(
        Account $account,
        Location $location,
        Room $room,
        ClassType $classType,
        string $title,
    ): ScheduledClass {
        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for(Trainer::factory()->for($account))
            ->create([
                'title' => $title,
                'starts_at' => '2026-08-26 18:00:00',
                'ends_at' => '2026-08-26 19:00:00',
            ]);
    }

    private function booking(
        Account $account,
        ScheduledClass $scheduledClass,
        Customer $customer,
        bool $skipClassPassReservation = false,
    ): ClassBooking {
        return ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create([
                'skip_class_pass_reservation' => $skipClassPassReservation,
            ]);
    }

    /**
     * @return array{0: ClassBooking, 1: CustomerClassPassReservation}
     */
    private function anyTimeBooking(
        Account $account,
        ScheduledClass $scheduledClass,
        ClassType $classType,
        Customer $customer,
    ): array {
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Morning with add-on',
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'sessions_count' => 4,
            'available_from_time' => null,
            'available_until_time' => '12:00:00',
            'allows_any_time' => true,
            'any_time_addon_price_cents' => 4500,
        ]);
        $plan->classTypes()->sync([$classType->id]);
        app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $booking = $this->booking($account, $scheduledClass, $customer);
        $reservation = app(ReserveCustomerClassPassForBooking::class)->execute($booking);
        $this->assertInstanceOf(CustomerClassPassReservation::class, $reservation);

        return [$booking, $reservation->load('customerClassPass')];
    }
}
