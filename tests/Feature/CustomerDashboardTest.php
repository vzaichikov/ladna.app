<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduleKind;
use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\TelegramBotInstallation;
use App\Support\MoneyFormatter;
use App\Support\Telegram\CustomerTelegramLinkResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerDashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dashboard_counts_and_lists_only_active_class_passes(): void
    {
        app()->setLocale('uk');

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-active-passes',
            'timezone' => 'UTC',
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Олена',
            'phone' => '+380501112233',
        ]);

        $this->classPass($account, $customer, [
            'code' => 'ACTIVE-001',
            'plan_name' => 'Active Pole',
            'sessions_count' => 10,
            'used_sessions_count' => 2,
            'reserved_sessions_count' => 3,
            'status' => CustomerClassPassStatus::Active->value,
            'is_active' => true,
        ]);
        $this->classPass($account, $customer, [
            'code' => 'CANCEL-001',
            'plan_name' => 'Cancelled Pole',
            'sessions_count' => 20,
            'used_sessions_count' => 1,
            'reserved_sessions_count' => 0,
            'status' => CustomerClassPassStatus::Cancelled->value,
            'is_active' => false,
        ]);
        $this->classPass($account, $customer, [
            'code' => 'FLAG-001',
            'plan_name' => 'Inactive Flag Pole',
            'sessions_count' => 7,
            'used_sessions_count' => 0,
            'reserved_sessions_count' => 0,
            'status' => CustomerClassPassStatus::Active->value,
            'is_active' => false,
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee('Залишок занять', false)
            ->assertSeeInOrder(['Записи', '0', 'Залишок занять', '5', 'активних абонементів', '1'], false)
            ->assertSeeInOrder(['Мої заняття', '0', 'Мої абонементи', '1'], false)
            ->assertDontSee('ACTIVE-001', false)
            ->assertDontSee('CANCEL-001', false)
            ->assertDontSee('Cancelled Pole', false)
            ->assertDontSee('FLAG-001', false)
            ->assertDontSee('Inactive Flag Pole', false);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', ['accountSlug' => $account->slug, 'tab' => 'passes']))
            ->assertOk()
            ->assertSee('ACTIVE-001', false)
            ->assertDontSee('CANCEL-001', false)
            ->assertDontSee('Cancelled Pole', false)
            ->assertDontSee('FLAG-001', false)
            ->assertDontSee('Inactive Flag Pole', false);
    }

    public function test_unopened_pass_uses_purchase_lifetime_as_use_by_date(): void
    {
        app()->setLocale('uk');

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-unopened-pass',
            'timezone' => 'UTC',
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Марія',
            'phone' => '+380501112234',
        ]);

        $this->classPass($account, $customer, [
            'code' => 'UNOPEN-001',
            'plan_name' => 'Unopened Pole',
            'purchased_at' => Carbon::parse('2026-06-30 10:00:00', 'UTC'),
            'opened_at' => null,
            'expires_at' => Carbon::parse('2026-07-31 23:59:00', 'UTC'),
            'usable_until_at' => Carbon::parse('2026-12-27 23:59:00', 'UTC'),
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', ['accountSlug' => $account->slug, 'tab' => 'passes']))
            ->assertOk()
            ->assertSeeInOrder(['Unopened Pole', 'Куплено', '2026-06-30', 'Відкрито', '—', 'Використати до', '2026-12-27'], false)
            ->assertDontSee('Строк з першого заняття до', false)
            ->assertDontSee('2026-07-31', false);
    }

    public function test_opened_pass_uses_opening_expiry_as_use_by_date(): void
    {
        app()->setLocale('uk');

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-opened-pass',
            'timezone' => 'UTC',
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Ірина',
            'phone' => '+380501112235',
        ]);

        $this->classPass($account, $customer, [
            'code' => 'OPENED-001',
            'plan_name' => 'Opened Pole',
            'purchased_at' => Carbon::parse('2026-06-30 10:00:00', 'UTC'),
            'opened_at' => Carbon::parse('2026-07-01 09:00:00', 'UTC'),
            'expires_at' => Carbon::parse('2026-07-31 23:59:00', 'UTC'),
            'usable_until_at' => Carbon::parse('2026-12-27 23:59:00', 'UTC'),
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', ['accountSlug' => $account->slug, 'tab' => 'passes']))
            ->assertOk()
            ->assertSeeInOrder(['Opened Pole', 'Куплено', '2026-06-30', 'Відкрито', '2026-07-01', 'Використати до', '2026-07-31'], false)
            ->assertDontSee('Строк з першого заняття до', false)
            ->assertDontSee('2026-12-27', false);
    }

    public function test_default_classes_tab_highlights_booking_without_active_class_pass(): void
    {
        app()->setLocale('uk');
        Carbon::setTestNow(Carbon::parse('2026-07-06 08:00:00', 'UTC'));

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-bookings-tab',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Юлія',
            'phone' => '+380501112236',
        ]);
        $customerClassPass = $this->classPass($account, $customer, [
            'code' => 'PASS-001',
            'plan_name' => 'Covered Pole',
            'sessions_count' => 1,
            'used_sessions_count' => 0,
            'reserved_sessions_count' => 1,
        ]);

        $coveredClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Covered Exot',
                'starts_at' => Carbon::parse('2026-07-07 10:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-07-07 11:00:00', 'UTC'),
            ]);
        $coveredBooking = ClassBooking::factory()
            ->for($account)
            ->for($coveredClass, 'scheduledClass')
            ->for($customer)
            ->create();
        CustomerClassPassReservation::factory()->create([
            'account_id' => $account->id,
            'customer_class_pass_id' => $customerClassPass->id,
            'class_booking_id' => $coveredBooking->id,
            'scheduled_class_id' => $coveredClass->id,
            'status' => 'reserved',
            'reserved_at' => Carbon::parse('2026-07-06 08:10:00', 'UTC'),
        ]);

        $uncoveredClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'No Pass Tricks',
                'starts_at' => Carbon::parse('2026-07-07 11:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-07-07 12:00:00', 'UTC'),
            ]);
        ClassBooking::factory()
            ->for($account)
            ->for($uncoveredClass, 'scheduledClass')
            ->for($customer)
            ->create();

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSeeInOrder(['Мої заняття', '2', 'Мої абонементи', '1'], false)
            ->assertSee('No Pass Tricks', false)
            ->assertSee('На це заняття немає активного абонемента.', false)
            ->assertSee('Covered Exot', false)
            ->assertSee('PASS-001', false);

        Carbon::setTestNow();
    }

    public function test_dashboard_separates_upcoming_and_history_with_truthful_booking_and_pass_states(): void
    {
        app()->setLocale('uk');
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-booking-sections',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass,
            'cancellation_cutoff_minutes' => 1440,
        ]);
        $customer = Customer::factory()->for($account)->create();
        $reservedPass = $this->classPass($account, $customer, ['code' => 'RESERVED-UX']);
        $releasedPass = $this->classPass($account, $customer, ['code' => 'RELEASED-UX']);
        $usedPass = $this->classPass($account, $customer, ['code' => 'USED-UX']);

        $nearClass = $this->scheduledClass($account, $location, $room, $classType, 'Nearest upcoming', '2026-07-10 13:00:00');
        $nearBooking = ClassBooking::factory()
            ->for($account)
            ->for($nearClass, 'scheduledClass')
            ->for($customer)
            ->create();
        CustomerClassPassReservation::factory()->create([
            'account_id' => $account->id,
            'customer_class_pass_id' => $reservedPass->id,
            'class_booking_id' => $nearBooking->id,
            'scheduled_class_id' => $nearClass->id,
            'status' => CustomerClassPassReservationStatus::Reserved,
        ]);

        $farClass = $this->scheduledClass($account, $location, $room, $classType, 'Later upcoming', '2026-07-13 10:00:00');
        $farBooking = ClassBooking::factory()
            ->for($account)
            ->for($farClass, 'scheduledClass')
            ->for($customer)
            ->create();

        $cancelledClass = $this->scheduledClass($account, $location, $room, $classType, 'Future cancellation', '2026-07-12 10:00:00');
        $cancelledBooking = ClassBooking::factory()
            ->for($account)
            ->for($cancelledClass, 'scheduledClass')
            ->for($customer)
            ->create(['status' => ClassBookingStatus::Cancelled]);
        CustomerClassPassReservation::factory()->create([
            'account_id' => $account->id,
            'customer_class_pass_id' => $releasedPass->id,
            'class_booking_id' => $cancelledBooking->id,
            'scheduled_class_id' => $cancelledClass->id,
            'status' => CustomerClassPassReservationStatus::Released,
            'released_at' => Carbon::parse('2026-07-10 09:00:00', 'UTC'),
        ]);

        $pastClass = $this->scheduledClass($account, $location, $room, $classType, 'Past unmarked booking', '2026-07-09 10:00:00');
        $pastBooking = ClassBooking::factory()
            ->for($account)
            ->for($pastClass, 'scheduledClass')
            ->for($customer)
            ->create(['status' => ClassBookingStatus::Booked]);
        CustomerClassPassReservation::factory()->create([
            'account_id' => $account->id,
            'customer_class_pass_id' => $usedPass->id,
            'class_booking_id' => $pastBooking->id,
            'scheduled_class_id' => $pastClass->id,
            'status' => CustomerClassPassReservationStatus::Used,
            'used_at' => $pastClass->starts_at,
        ]);

        $attendedClass = $this->scheduledClass($account, $location, $room, $classType, 'Attended booking', '2026-07-08 10:00:00');
        $attendedBooking = ClassBooking::factory()
            ->for($account)
            ->for($attendedClass, 'scheduledClass')
            ->for($customer)
            ->create(['status' => ClassBookingStatus::Attended]);

        $noShowClass = $this->scheduledClass($account, $location, $room, $classType, 'No-show booking', '2026-07-07 10:00:00');
        $noShowBooking = ClassBooking::factory()
            ->for($account)
            ->for($noShowClass, 'scheduledClass')
            ->for($customer)
            ->create(['status' => ClassBookingStatus::NoShow]);

        $response = $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug));

        $response
            ->assertOk()
            ->assertViewHas('upcomingBookings', fn ($bookings): bool => $bookings->pluck('id')->all() === [
                $nearBooking->id,
                $farBooking->id,
            ])
            ->assertViewHas('bookingHistory', null)
            ->assertViewHas('bookingHistoryCount', 4)
            ->assertSeeInOrder([
                __('app.customer_dashboard_upcoming_classes'),
                'Nearest upcoming',
                'Later upcoming',
            ], false)
            ->assertDontSee('Future cancellation', false)
            ->assertDontSee('Past unmarked booking', false)
            ->assertSee('data-booking-section="upcoming"', false)
            ->assertDontSee('data-booking-section="history"', false);

        $nearCard = $this->bookingCardHtml($response, $nearBooking);
        $this->assertStringContainsString('crm-status-scheduled">'.__('app.booked'), $nearCard);
        $this->assertStringContainsString('RESERVED-UX', $nearCard);
        $this->assertStringContainsString('crm-status-scheduled">'.__('app.reserved'), $nearCard);
        $this->assertStringContainsString(__('app.booking_cancellation_cutoff_marker'), $nearCard);

        $historyResponse = $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', ['accountSlug' => $account->slug, 'tab' => 'history']));

        $historyResponse
            ->assertOk()
            ->assertViewHas('dashboardTab', 'history')
            ->assertViewHas('bookingHistory', fn ($bookings): bool => $bookings->total() === 4
                && $bookings->getCollection()->pluck('id')->all() === [
                    $cancelledBooking->id,
                    $pastBooking->id,
                    $attendedBooking->id,
                    $noShowBooking->id,
                ])
            ->assertSeeInOrder([
                __('app.customer_dashboard_booking_history'),
                'Future cancellation',
                'Past unmarked booking',
                'Attended booking',
                'No-show booking',
            ], false)
            ->assertDontSee('Nearest upcoming', false)
            ->assertDontSee('Later upcoming', false)
            ->assertSee('data-booking-section="history"', false)
            ->assertDontSee('data-booking-section="upcoming"', false);

        $cancelledCard = $this->bookingCardHtml($historyResponse, $cancelledBooking);
        $this->assertStringContainsString('crm-status-muted">'.__('app.cancelled'), $cancelledCard);
        $this->assertStringContainsString('RELEASED-UX', $cancelledCard);
        $this->assertStringContainsString('crm-status-muted">'.__('app.released'), $cancelledCard);
        $this->assertStringNotContainsString(__('app.booking_cancellation_cutoff_marker'), $cancelledCard);

        $pastCard = $this->bookingCardHtml($historyResponse, $pastBooking);
        $this->assertStringContainsString('crm-status-scheduled">'.__('app.booked'), $pastCard);
        $this->assertStringContainsString('USED-UX', $pastCard);
        $this->assertStringContainsString('crm-status-active">'.__('app.used'), $pastCard);
        $this->assertStringNotContainsString(__('app.booking_cancellation_cutoff_marker'), $pastCard);

        $this->assertStringContainsString('crm-status-active">'.__('app.attended'), $this->bookingCardHtml($historyResponse, $attendedBooking));
        $this->assertStringContainsString('crm-status-danger">'.__('app.no_show'), $this->bookingCardHtml($historyResponse, $noShowBooking));

        Carbon::setTestNow();
    }

    public function test_booking_history_tab_is_paginated_and_preserves_its_tab_query(): void
    {
        app()->setLocale('uk');
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'UTC'));

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-history-pagination',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass,
        ]);
        $customer = Customer::factory()->for($account)->create();

        foreach (range(1, 11) as $daysAgo) {
            $scheduledClass = $this->scheduledClass(
                $account,
                $location,
                $room,
                $classType,
                sprintf('History %02d', $daysAgo),
                now()->subDays($daysAgo)->format('Y-m-d H:i:s'),
            );
            ClassBooking::factory()
                ->for($account)
                ->for($scheduledClass, 'scheduledClass')
                ->for($customer)
                ->create(['status' => ClassBookingStatus::Attended]);
        }

        $firstPage = $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', ['accountSlug' => $account->slug, 'tab' => 'history']));

        $firstPage
            ->assertOk()
            ->assertViewHas('bookingHistoryCount', 11)
            ->assertViewHas('bookingHistory', fn ($bookings): bool => $bookings->total() === 11
                && $bookings->perPage() === 10
                && $bookings->currentPage() === 1
                && $bookings->count() === 10)
            ->assertSeeInOrder(['History 01', 'History 02', 'History 10'], false)
            ->assertDontSee('History 11', false)
            ->assertSee('booking_history_page=2', false)
            ->assertSee('tab=history', false)
            ->assertSee('Показано', false)
            ->assertSee('результатів', false)
            ->assertSee('Далі', false)
            ->assertDontSee('Showing', false);
        $this->assertSame(10, substr_count($firstPage->getContent(), 'data-customer-booking="'));

        $secondPage = $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', [
                'accountSlug' => $account->slug,
                'booking_history_page' => 2,
            ]));

        $secondPage
            ->assertOk()
            ->assertViewHas('dashboardTab', 'history')
            ->assertViewHas('bookingHistory', fn ($bookings): bool => $bookings->currentPage() === 2
                && $bookings->count() === 1)
            ->assertSee('History 11', false)
            ->assertDontSee('History 01', false)
            ->assertSee('Назад', false);
        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-customer-booking="'));

        Carbon::setTestNow();
    }

    public function test_dashboard_distinguishes_manual_payment_debt_from_an_uncovered_group_booking(): void
    {
        app()->setLocale('uk');
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-payment-kinds',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $groupClassType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass,
        ]);
        $roomRentalClassType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental,
        ]);
        $customer = Customer::factory()->for($account)->create();

        $groupClass = $this->scheduledClass($account, $location, $room, $groupClassType, 'Uncovered group class', '2026-07-13 10:00:00');
        $groupBooking = ClassBooking::factory()
            ->for($account)
            ->for($groupClass, 'scheduledClass')
            ->for($customer)
            ->create();

        $roomRental = $this->scheduledClass($account, $location, $room, $roomRentalClassType, 'Room rental payment', '2026-07-13 12:00:00');
        $roomRentalBooking = ClassBooking::factory()
            ->for($account)
            ->for($roomRental, 'scheduledClass')
            ->for($customer)
            ->create(['skip_class_pass_reservation' => true]);

        $response = $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk();

        $groupCard = $this->bookingCardHtml($response, $groupBooking);
        $this->assertStringContainsString(__('app.customer_booking_without_class_pass_alert'), $groupCard);
        $this->assertStringNotContainsString(__('app.unpaid_class_booking_payment_alert'), $groupCard);

        $roomRentalCard = $this->bookingCardHtml($response, $roomRentalBooking);
        $this->assertStringContainsString(__('app.unpaid_class_booking_payment_alert'), $roomRentalCard);
        $this->assertStringContainsString(__('app.unpaid_class_booking_payment_reason_room_rental'), $roomRentalCard);
        $this->assertStringNotContainsString(__('app.customer_booking_without_class_pass_alert'), $roomRentalCard);

        Carbon::setTestNow();
    }

    public function test_used_up_pass_still_covers_the_booking_that_consumed_it(): void
    {
        app()->setLocale('uk');
        Carbon::setTestNow(Carbon::parse('2026-07-08 08:00:00', 'UTC'));

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-used-up-pass',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Ганна',
            'phone' => '+380501112239',
        ]);
        $customerClassPass = $this->classPass($account, $customer, [
            'code' => 'USED-UP-001',
            'plan_name' => 'Trial Exot',
            'sessions_count' => 1,
            'used_sessions_count' => 1,
            'reserved_sessions_count' => 0,
            'status' => CustomerClassPassStatus::UsedUp->value,
            'is_active' => false,
            'opened_at' => Carbon::parse('2026-07-07 10:00:00', 'UTC'),
            'closed_at' => Carbon::parse('2026-07-07 12:00:00', 'UTC'),
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Covered Used Exot',
                'starts_at' => Carbon::parse('2026-07-07 10:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-07-07 11:00:00', 'UTC'),
            ]);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($customer)
            ->create();
        CustomerClassPassReservation::factory()->create([
            'account_id' => $account->id,
            'customer_class_pass_id' => $customerClassPass->id,
            'class_booking_id' => $booking->id,
            'scheduled_class_id' => $scheduledClass->id,
            'status' => 'used',
            'reserved_at' => Carbon::parse('2026-07-06 08:10:00', 'UTC'),
            'used_at' => $scheduledClass->starts_at,
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', ['accountSlug' => $account->slug, 'tab' => 'history']))
            ->assertOk()
            ->assertSeeInOrder(['Залишок занять', '0', 'активних абонементів', '0'], false)
            ->assertSee('Covered Used Exot', false)
            ->assertSee('USED-UP-001', false)
            ->assertDontSee('На це заняття немає активного абонемента.', false);

        Carbon::setTestNow();
    }

    public function test_default_classes_tab_shows_any_time_addon_instead_of_missing_pass_alert(): void
    {
        app()->setLocale('uk');
        Carbon::setTestNow(Carbon::parse('2026-07-06 08:00:00', 'UTC'));

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-any-time-addon',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Катерина',
            'phone' => '+380501112237',
        ]);
        $customerClassPass = $this->classPass($account, $customer, [
            'code' => 'MORN-001',
            'plan_name' => 'Morning Pole',
            'sessions_count' => 4,
            'used_sessions_count' => 0,
            'reserved_sessions_count' => 1,
            'available_from_time' => null,
            'available_until_time' => '12:00:00',
            'allows_any_time' => true,
            'any_time_addon_price_cents' => 4500,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Evening Exot',
                'starts_at' => Carbon::parse('2026-07-07 18:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-07-07 19:00:00', 'UTC'),
            ]);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($customer)
            ->create();
        CustomerClassPassReservation::factory()->create([
            'account_id' => $account->id,
            'customer_class_pass_id' => $customerClassPass->id,
            'class_booking_id' => $booking->id,
            'scheduled_class_id' => $scheduledClass->id,
            'status' => 'reserved',
            'reserved_at' => Carbon::parse('2026-07-06 08:10:00', 'UTC'),
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee('Evening Exot', false)
            ->assertSee('MORN-001', false)
            ->assertSee(__('app.unpaid_class_booking_payment_alert'), false)
            ->assertSee(__('app.customer_booking_any_time_addon_due', ['amount' => MoneyFormatter::format(4500, 'UAH')]), false)
            ->assertDontSee('На це заняття немає активного абонемента.', false);

        Carbon::setTestNow();
    }

    public function test_customer_dashboard_shows_public_links_for_active_studio_locations(): void
    {
        app()->setLocale('uk');

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-dashboard-public-links',
            'timezone' => 'UTC',
        ]);
        $activeLocation = Location::factory()->for($account)->create([
            'name' => 'Public Main',
            'slug' => 'public-main',
        ]);
        $inactiveLocation = Location::factory()->for($account)->create([
            'name' => 'Closed Main',
            'slug' => 'closed-main',
            'is_active' => false,
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Alice',
            'phone' => '+380501112238',
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee(__('app.customer_dashboard_buy_class_pass'))
            ->assertSee(__('app.customer_dashboard_book_class'))
            ->assertDontSee(__('app.public_links'))
            ->assertDontSee(__('app.public_links_copy'))
            ->assertDontSee($activeLocation->name)
            ->assertSee(route('public.price', [$account->slug, $activeLocation->slug]), false)
            ->assertSee(route('public.schedule', [$account->slug, $activeLocation->slug]), false)
            ->assertDontSee($inactiveLocation->name)
            ->assertDontSee(route('public.price', [$account->slug, $inactiveLocation->slug]), false)
            ->assertDontSee(route('public.schedule', [$account->slug, $inactiveLocation->slug]), false);
    }

    public function test_customer_dashboard_shows_only_its_enabled_studio_bot_link(): void
    {
        $account = Account::factory()->create([
            'slug' => 'customer-dashboard-telegram-link',
            'default_language' => 'uk',
        ]);
        $customer = Customer::factory()->for($account)->create();
        $profile = $account->telegramBotProfiles()->create([
            'profile' => TelegramBotProfile::Customer->value,
            'mode' => TelegramBotMode::Simple->value,
            'is_enabled' => true,
            'settings' => [
                CustomerTelegramLinkResolver::PlacementSettingsKey => [
                    CustomerTelegramLinkResolver::PlacementCustomerDashboard => true,
                ],
            ],
        ]);
        $otherAccount = Account::factory()->create();
        TelegramBotInstallation::factory()->for($otherAccount)->create([
            'profile' => TelegramBotProfile::Customer->value,
            'bot_username' => 'other_studio_bot',
        ]);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertDontSee('other_studio_bot', false)
            ->assertDontSee('data-customer-telegram-bot-link="customer-dashboard"', false);

        TelegramBotInstallation::factory()->for($account)->create([
            'profile' => TelegramBotProfile::Customer->value,
            'bot_username' => '@customer_dashboard_bot',
        ]);
        $botLink = 'https://t.me/customer_dashboard_bot?start=ladna';

        $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee('data-customer-telegram-bot-link="customer-dashboard"', false)
            ->assertSee($botLink, false)
            ->assertSee(__('app.customer_telegram_booking_bot', [], 'uk'))
            ->assertSee('assets/social/telegram.svg', false);

        $profile->forceFill(['settings' => [
            CustomerTelegramLinkResolver::PlacementSettingsKey => [
                CustomerTelegramLinkResolver::PlacementCustomerDashboard => false,
            ],
        ]])->save();

        $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertDontSee($botLink, false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function classPass(Account $account, Customer $customer, array $attributes = []): CustomerClassPass
    {
        $classPassPlan = ClassPassPlan::factory()->for($account)->create();

        return CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($classPassPlan)
            ->create(array_merge([
                'status' => CustomerClassPassStatus::Active->value,
                'is_active' => true,
                'purchased_at' => Carbon::parse('2026-06-01 10:00:00', 'UTC'),
                'opened_at' => null,
                'expires_at' => null,
                'usable_until_at' => Carbon::parse('2026-12-01 10:00:00', 'UTC'),
            ], $attributes));
    }

    private function scheduledClass(
        Account $account,
        Location $location,
        Room $room,
        ClassType $classType,
        string $title,
        string $startsAt,
    ): ScheduledClass {
        $startsAtDate = Carbon::parse($startsAt, 'UTC');

        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => $title,
                'starts_at' => $startsAtDate,
                'ends_at' => $startsAtDate->copy()->addHour(),
            ]);
    }

    private function bookingCardHtml(TestResponse $response, ClassBooking $booking): string
    {
        preg_match(
            '/<article[^>]*data-customer-booking="'.preg_quote((string) $booking->id, '/').'"[^>]*>.*?<\\/article>/s',
            $response->getContent(),
            $matches,
        );

        $this->assertArrayHasKey(0, $matches, "Booking card {$booking->id} was not rendered.");

        return $matches[0];
    }
}
