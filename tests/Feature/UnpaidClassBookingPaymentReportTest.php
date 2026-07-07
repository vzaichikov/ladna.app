<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\RecordManualClassBookingPayment;
use App\Actions\ReserveCustomerClassPassForBooking;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UnpaidClassBookingPaymentReportTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_dashboard_schedule_and_report_show_missing_booking_payments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 09:00:00', 'UTC'));

        [$owner, $account, $location, $room] = $this->studio();
        $rentalType = $this->classType($account, ScheduleKind::RoomRental, 'Room rental');
        $groupType = $this->classType($account, ScheduleKind::GroupClass, 'Pole group');
        $rentalClass = $this->scheduledClass($account, $location, $room, $rentalType, 'Direct Rent', '2026-07-07 10:00:00');
        $paidRentalClass = $this->scheduledClass($account, $location, $room, $rentalType, 'Paid Rent', '2026-07-07 12:00:00');
        $groupClass = $this->scheduledClass($account, $location, $room, $groupType, 'Evening Pole', '2026-07-07 18:00:00');
        $rentalCustomer = Customer::factory()->for($account)->create(['name' => 'Rent Client']);
        $paidRentalCustomer = Customer::factory()->for($account)->create(['name' => 'Paid Rent Client']);
        $anyTimeCustomer = Customer::factory()->for($account)->create(['name' => 'Any Time Client']);
        $rentalBooking = $this->booking($account, $rentalClass, $rentalCustomer, skipClassPassReservation: true);
        $paidRentalBooking = $this->booking($account, $paidRentalClass, $paidRentalCustomer, skipClassPassReservation: true);
        app(RecordManualClassBookingPayment::class)->execute($account, $paidRentalBooking, 25000);
        $this->anyTimeBooking($account, $groupClass, $groupType, $anyTimeCustomer);

        $reportResponse = $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.unpaid-class-payments', $account))
            ->assertOk()
            ->assertSee(__('app.unpaid_class_booking_payments_report_title'))
            ->assertSee(__('app.unpaid_class_booking_payment_alert'))
            ->assertSee('Direct Rent')
            ->assertSee('Rent Client')
            ->assertSee('Evening Pole')
            ->assertSee('Any Time Client')
            ->assertSee(MoneyFormatter::format(4500, 'UAH'))
            ->assertSee('value="45.00"', false)
            ->assertDontSee('Paid Rent Client');

        $this->assertStringContainsString((string) $rentalBooking->id, $reportResponse->content());

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee(__('app.problem_unpaid_class_booking_payments'))
            ->assertSee(route('dashboard.accounts.reports.unpaid-class-payments', $account), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSee(__('app.unpaid_class_booking_payment_alert'))
            ->assertSee('Direct Rent')
            ->assertSee('Evening Pole');
    }

    public function test_report_payment_form_records_direct_rental_payment_and_returns_to_report(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 09:00:00', 'UTC'));

        [$owner, $account, $location, $room] = $this->studio();
        $rentalType = $this->classType($account, ScheduleKind::RoomRental, 'Room rental');
        $rentalClass = $this->scheduledClass($account, $location, $room, $rentalType, 'Direct Rent', '2026-07-07 10:00:00');
        $customer = Customer::factory()->for($account)->create(['name' => 'Rent Client']);
        $booking = $this->booking($account, $rentalClass, $customer, skipClassPassReservation: true);
        $reportUrl = route('dashboard.accounts.reports.unpaid-class-payments', [
            'account' => $account,
            'page' => 1,
        ]);
        $reportPath = parse_url($reportUrl, PHP_URL_PATH).'?page=1';

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.bookings.payment.store', [$account, $booking]), [
                'amount' => '300.00',
                'return_to' => $reportUrl,
            ])
            ->assertRedirect($reportPath)
            ->assertSessionHas('status', __('app.class_booking_payment_recorded'));

        $payment = CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->where('class_booking_id', $booking->id)
            ->firstOrFail();

        $this->assertSame(CustomerPurchase::SourceManualCashBooking, $payment->payment_source);
        $this->assertSame(CustomerPurchaseStatus::PaymentPaid, $payment->status);
        $this->assertSame(30000, $payment->amount_cents);

        $this->actingAs($owner)
            ->get($reportUrl)
            ->assertOk()
            ->assertDontSee('Rent Client')
            ->assertSee(__('app.no_unpaid_class_booking_payments'));
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
        string $startsAt,
    ): ScheduledClass {
        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for(Trainer::factory()->for($account))
            ->create([
                'title' => $title,
                'starts_at' => $startsAt,
                'ends_at' => Carbon::parse($startsAt, 'UTC')->addHour(),
            ]);
    }

    private function booking(Account $account, ScheduledClass $scheduledClass, Customer $customer, bool $skipClassPassReservation = false): ClassBooking
    {
        return ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create([
                'skip_class_pass_reservation' => $skipClassPassReservation,
            ]);
    }

    private function anyTimeBooking(Account $account, ScheduledClass $scheduledClass, ClassType $classType, Customer $customer): ClassBooking
    {
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
        app(ReserveCustomerClassPassForBooking::class)->execute($booking);

        return $booking;
    }
}
