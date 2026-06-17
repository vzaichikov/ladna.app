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
use Tests\TestCase;

class CustomerBookingTest extends TestCase
{
    use DatabaseTransactions;

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
}
