<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Enums\WebsiteLeadStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use App\Models\WebsiteLead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuickBookingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_quick_book_private_lesson_with_new_customer_and_class_type_defaults(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC', 'default_language' => 'uk']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 8]);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Private 75',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 75,
            'booking_cutoff_minutes' => 15,
            'default_capacity' => 2,
        ]);
        $trainer = Trainer::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-17T15:00',
                'customer_phone' => '+380671112233',
                'customer_name' => 'Олена Коваль',
                'notes' => 'Incoming call',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', __('app.quick_booking_created'));

        $customer = Customer::whereBelongsTo($account)->where('phone', '+380671112233')->firstOrFail();
        $scheduledClass = ScheduledClass::whereBelongsTo($account)->where('title', 'Private 75')->firstOrFail();
        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();

        $this->assertSame('Олена Коваль', $customer->name);
        $this->assertSame('uk', $customer->default_language);
        $this->assertSame($classType->id, $scheduledClass->class_type_id);
        $this->assertSame($trainer->id, $scheduledClass->trainer_id);
        $this->assertSame(2, $scheduledClass->capacity);
        $this->assertSame(15, $scheduledClass->booking_cutoff_minutes);
        $this->assertSame('quick_booking', $scheduledClass->metadata['source']);
        $this->assertSame(75, (int) $scheduledClass->starts_at->diffInMinutes($scheduledClass->ends_at));
        $this->assertSame($scheduledClass->id, $booking->scheduled_class_id);
        $this->assertSame('Incoming call', $booking->notes);

        Carbon::setTestNow();
    }

    public function test_owner_can_quick_book_group_class_and_convert_website_lead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Pole Beginner',
                'starts_at' => '2026-06-17 15:00:00',
                'ends_at' => '2026-06-17 16:00:00',
                'capacity' => 3,
            ]);
        $websiteLead = WebsiteLead::factory()->for($account)->create([
            'phone' => '+380501234567',
            'name' => 'Марія',
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
                'website_lead_id' => $websiteLead->id,
                'customer_phone' => '+380501234567',
                'customer_name' => 'Марія',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', __('app.quick_booking_created'))
            ->assertJsonPath('scheduled_class_id', $scheduledClass->id);

        $customer = Customer::whereBelongsTo($account)->where('phone', '+380501234567')->firstOrFail();
        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();
        $websiteLead->refresh();

        $this->assertSame($scheduledClass->id, $booking->scheduled_class_id);
        $this->assertSame(WebsiteLeadStatus::Booked, $websiteLead->status);
        $this->assertSame($customer->id, $websiteLead->customer_id);
        $this->assertSame($booking->id, $websiteLead->class_booking_id);
        $this->assertNotNull($websiteLead->converted_at);

        Carbon::setTestNow();
    }

    public function test_group_availability_returns_only_classes_with_free_slots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Nastya']);
        $availableClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'title' => 'Available Class',
                'starts_at' => '2026-06-17 18:00:00',
                'ends_at' => '2026-06-17 19:00:00',
                'capacity' => 2,
            ]);
        $fullClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'title' => 'Full Class',
                'starts_at' => '2026-06-17 19:00:00',
                'ends_at' => '2026-06-17 20:00:00',
                'capacity' => 1,
            ]);
        ClassBooking::factory()
            ->for($account)
            ->for($fullClass)
            ->for(Customer::factory()->for($account))
            ->create(['status' => ClassBookingStatus::Booked->value]);

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.group-availability', [
                'account' => $account,
                'date' => '2026-06-17',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $availableClass->id)
            ->assertJsonPath('data.0.available_spots', 2)
            ->assertJsonPath('data.0.trainer', 'Nastya')
            ->assertJsonMissing(['title' => 'Full Class']);

        Carbon::setTestNow();
    }

    public function test_group_availability_uses_account_timezone_for_requested_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 02:30:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'America/New_York']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'America/New_York']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        $trainer = Trainer::factory()->for($account)->create();

        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'title' => 'Past Local Class',
                'starts_at' => '2026-06-17 01:00:00',
                'ends_at' => '2026-06-17 02:00:00',
                'capacity' => 2,
            ]);
        $availableClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'title' => 'Late Local Class',
                'starts_at' => '2026-06-17 03:30:00',
                'ends_at' => '2026-06-17 04:30:00',
                'capacity' => 2,
            ]);
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'title' => 'Next Local Day Class',
                'starts_at' => '2026-06-17 04:30:00',
                'ends_at' => '2026-06-17 05:30:00',
                'capacity' => 2,
            ]);

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.group-availability', [
                'account' => $account,
                'date' => '2026-06-16',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $availableClass->id)
            ->assertJsonPath('data.0.time', '23:30')
            ->assertJsonMissing(['title' => 'Past Local Class'])
            ->assertJsonMissing(['title' => 'Next Local Day Class']);

        Carbon::setTestNow();
    }
}
