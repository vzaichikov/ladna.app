<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerBookingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_scheduled_classes_index(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();

        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Pole Beginner',
                'starts_at' => Carbon::parse('2026-06-17 10:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-06-17 11:00:00', 'UTC'),
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSee('Pole Beginner');

        Carbon::setTestNow();
    }

    public function test_owner_can_create_customer_book_class_and_mark_attendance(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.store', $account), [
                'name' => 'Олена',
                'phone' => '+380671112233',
                'email' => 'olena@example.com',
                'default_language' => 'uk',
            ])
            ->assertRedirect(route('dashboard.accounts.customers.index', $account));

        $customer = Customer::whereBelongsTo($account)->where('email', 'olena@example.com')->firstOrFail();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $customer->id,
                'notes' => 'First visit',
            ])
            ->assertRedirect(route('dashboard.accounts.scheduled-classes.index', $account));

        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.bookings.update', [$account, $booking]), [
                'status' => 'attended',
            ])
            ->assertRedirect(route('dashboard.accounts.scheduled-classes.index', $account));

        $booking->refresh();
        $this->assertSame('attended', $booking->status->value);
        $this->assertNotNull($booking->attended_at);
    }

    public function test_booking_rejects_customer_from_another_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $otherCustomer->id,
            ])
            ->assertInvalid('customer_id');
    }

    public function test_scheduled_class_tabs_filter_by_account_and_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $otherLocation = Location::factory()->for($otherAccount)->create(['timezone' => 'UTC']);
        $otherRoom = Room::factory()->for($otherAccount)->for($otherLocation)->create();
        $otherClassType = ClassType::factory()->for($otherAccount)->create();

        $this->scheduledClass($account, $location, $room, $classType, 'Today Class', '2026-06-17 10:00:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Tomorrow Class', '2026-06-18 10:00:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Sunday Class', '2026-06-21 10:00:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Next Monday Class', '2026-06-22 10:00:00');
        $this->scheduledClass($otherAccount, $otherLocation, $otherRoom, $otherClassType, 'Other Account Class', '2026-06-17 10:00:00');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSee('Today Class')
            ->assertDontSee('Tomorrow Class')
            ->assertDontSee('Other Account Class');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => 'this_week']))
            ->assertOk()
            ->assertSee('Today Class')
            ->assertSee('Tomorrow Class')
            ->assertSee('Sunday Class')
            ->assertDontSee('Next Monday Class')
            ->assertDontSee('Other Account Class');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => 'next_week']))
            ->assertOk()
            ->assertSee('Next Monday Class')
            ->assertDontSee('Sunday Class')
            ->assertDontSee('Other Account Class');

        Carbon::setTestNow();
    }

    public function test_scheduled_classes_can_be_filtered_by_locations_and_rooms(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $firstLocation = Location::factory()->for($account)->create(['name' => 'First Studio', 'timezone' => 'UTC']);
        $secondLocation = Location::factory()->for($account)->create(['name' => 'Second Studio', 'timezone' => 'UTC']);
        $firstRoom = Room::factory()->for($account)->for($firstLocation)->create(['name' => 'Blue Room']);
        $secondRoom = Room::factory()->for($account)->for($secondLocation)->create(['name' => 'Pink Room']);
        $classType = ClassType::factory()->for($account)->create();
        $otherLocation = Location::factory()->for($otherAccount)->create(['timezone' => 'UTC']);
        $otherRoom = Room::factory()->for($otherAccount)->for($otherLocation)->create();
        $otherClassType = ClassType::factory()->for($otherAccount)->create();
        $firstClass = $this->scheduledClass($account, $firstLocation, $firstRoom, $classType, 'First Location Class', '2026-06-17 10:00:00');
        $this->scheduledClass($account, $secondLocation, $secondRoom, $classType, 'Second Room Class', '2026-06-17 11:00:00');
        $this->scheduledClass($otherAccount, $otherLocation, $otherRoom, $otherClassType, 'Other Account Class', '2026-06-17 12:00:00');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => 'this_week']))
            ->assertOk()
            ->assertSee('First Location Class')
            ->assertSee('Second Room Class')
            ->assertSee('href="#scheduled-class-'.$firstClass->id.'"', false)
            ->assertDontSee('Other Account Class');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', [
                'account' => $account,
                'tab' => 'this_week',
                'locations' => [$firstLocation->id],
            ]))
            ->assertOk()
            ->assertSee('First Location Class')
            ->assertDontSee('Second Room Class')
            ->assertDontSee('Other Account Class');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', [
                'account' => $account,
                'tab' => 'this_week',
                'rooms' => [$secondRoom->id],
            ]))
            ->assertOk()
            ->assertSee('Second Room Class')
            ->assertDontSee('First Location Class')
            ->assertDontSee('Other Account Class');

        Carbon::setTestNow();
    }

    public function test_customer_search_matches_same_account_customer_fields(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        Customer::factory()->for($account)->create([
            'name' => 'Олена Коваль',
            'phone' => '+380671112233',
            'email' => 'olena.koval@example.com',
        ]);
        Customer::factory()->for($account)->create([
            'name' => 'Марія Шевченко',
            'phone' => '+380501234567',
            'email' => 'maria@example.com',
        ]);
        Customer::factory()->for($otherAccount)->create([
            'name' => 'Олена Інша',
            'phone' => '+380671112233',
            'email' => 'other@example.com',
        ]);

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.customers.search', ['account' => $account, 'q' => 'olena']))
            ->assertOk()
            ->assertJsonFragment(['email' => 'olena.koval@example.com'])
            ->assertJsonMissing(['email' => 'other@example.com']);

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.customers.search', ['account' => $account, 'q' => '1234567']))
            ->assertOk()
            ->assertJsonFragment(['email' => 'maria@example.com']);
    }

    private function scheduledClass(Account $account, Location $location, Room $room, ClassType $classType, string $title, string $startsAt): ScheduledClass
    {
        $startsAt = Carbon::parse($startsAt, 'UTC');

        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => $title,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);
    }
}
