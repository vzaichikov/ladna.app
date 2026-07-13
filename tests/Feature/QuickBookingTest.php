<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\WebsiteLeadStatus;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerPrivateTimeframe;
use App\Models\User;
use App\Models\WebsiteLead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuickBookingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_private_lesson_modal_shows_locked_location_and_room_selectors_and_hides_manual_time_field(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'trainer_private_timeframes_enabled' => true,
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Only Location']);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Only Hall']);
        ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
        ]);
        Trainer::factory()->for($account)->create();

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk();

        $privateLessonModal = Str::between(
            (string) $response->getContent(),
            'data-quick-booking-modal="private_lesson"',
            'data-quick-booking-modal="room_rental"',
        );

        $this->assertNotSame('', $privateLessonModal);
        $this->assertMatchesRegularExpression('/<select(?=[^>]*data-quick-booking-location)(?=[^>]*data-async-field="location_id")(?=[^>]*disabled)[^>]*>/', $privateLessonModal);
        $this->assertMatchesRegularExpression('/<input(?=[^>]*type="hidden")(?=[^>]*name="location_id")(?=[^>]*value="'.$location->id.'")(?=[^>]*data-quick-booking-location-value)[^>]*>/', $privateLessonModal);
        $this->assertMatchesRegularExpression('/<select(?=[^>]*data-quick-booking-room)(?=[^>]*data-async-field="room_id")(?=[^>]*disabled)[^>]*>/', $privateLessonModal);
        $this->assertMatchesRegularExpression('/<input(?=[^>]*type="hidden")(?=[^>]*name="room_id")(?=[^>]*value="'.$room->id.'")(?=[^>]*data-quick-booking-room-value)[^>]*>/', $privateLessonModal);
        $this->assertStringContainsString('Only Location', $privateLessonModal);
        $this->assertStringContainsString('Only Hall', $privateLessonModal);
        $this->assertStringNotContainsString('Only Location · Only Hall', $privateLessonModal);
        $this->assertMatchesRegularExpression('/<input(?=[^>]*type="hidden")(?=[^>]*data-manual-booking-time)(?=[^>]*data-async-field="starts_at")[^>]*>/', $privateLessonModal);
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*type="time")(?=[^>]*data-manual-booking-time)[^>]*>/', $privateLessonModal);
    }

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
            'cancellation_cutoff_minutes' => 1440,
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
        $this->assertSame(1440, $scheduledClass->cancellation_cutoff_minutes);
        $this->assertSame('quick_booking', $scheduledClass->metadata['source']);
        $this->assertSame(75, (int) $scheduledClass->starts_at->diffInMinutes($scheduledClass->ends_at));
        $this->assertSame($scheduledClass->id, $booking->scheduled_class_id);
        $this->assertSame('Incoming call', $booking->notes);
        $this->assertSame($owner->id, $booking->booked_by_actor_user_id);
        $this->assertSame($owner->name, $booking->booked_by_actor_name);
        $this->assertSame('owner', $booking->booked_by_actor_role);

        Carbon::setTestNow();
    }

    public function test_owner_can_quick_book_private_lesson_for_previous_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-23 15:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 8]);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Past Private 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
            'default_capacity' => 1,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-22T14:00',
                'customer_id' => $customer->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', __('app.quick_booking_created'));

        $scheduledClass = ScheduledClass::whereBelongsTo($account)->where('title', 'Past Private 60')->firstOrFail();
        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();

        $this->assertSame($scheduledClass->id, $booking->scheduled_class_id);
        $this->assertSame('2026-06-22 14:00:00', $scheduledClass->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($trainer->id, $scheduledClass->trainer_id);

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

    public function test_manual_availability_returns_opening_hour_slots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '12:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
        ]);

        $response = $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
            ]))
            ->assertOk()
            ->assertJsonPath('closed', false)
            ->assertJsonCount(3, 'data');

        $this->assertSame(['10:00', '10:30', '11:00'], array_column($response->json('data'), 'time'));

        Carbon::setTestNow();
    }

    public function test_room_rental_manual_availability_includes_past_slots_for_owner_entry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 11:15:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '12:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
        ]);

        $response = $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
            ]))
            ->assertOk();

        $this->assertSame(['10:00', '10:30', '11:00'], array_column($response->json('data'), 'time'));

        Carbon::setTestNow();
    }

    public function test_private_lesson_manual_availability_includes_past_slots_for_owner_entry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 11:15:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '12:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create();

        $response = $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
            ]))
            ->assertOk();

        $this->assertSame(['10:00', '10:30', '11:00'], array_column($response->json('data'), 'time'));

        Carbon::setTestNow();
    }

    public function test_manual_availability_returns_closed_day_without_slots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => false, 'opens_at' => '10:00', 'closes_at' => '12:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
        ]);

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
            ]))
            ->assertOk()
            ->assertJsonPath('closed', true)
            ->assertJsonCount(0, 'data');

        Carbon::setTestNow();
    }

    public function test_manual_availability_excludes_room_overlaps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '13:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
        ]);
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'starts_at' => '2026-06-22 10:30:00',
                'ends_at' => '2026-06-22 11:30:00',
            ]);

        $response = $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
            ]))
            ->assertOk();

        $this->assertSame(['11:30', '12:00'], array_column($response->json('data'), 'time'));

        Carbon::setTestNow();
    }

    public function test_manual_availability_excludes_private_trainer_overlaps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '13:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $otherRoom = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($otherRoom)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => '2026-06-22 10:30:00',
                'ends_at' => '2026-06-22 11:30:00',
            ]);

        $response = $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
            ]))
            ->assertOk();

        $this->assertSame(['11:30', '12:00'], array_column($response->json('data'), 'time'));

        Carbon::setTestNow();
    }

    public function test_quick_booking_rejects_unavailable_manual_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Private 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => '2026-06-22 14:30:00',
                'ends_at' => '2026-06-22 15:30:00',
            ]);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.scheduled-classes.index', $account))
            ->post(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-22T14:00',
                'customer_phone' => '+380671112233',
                'customer_name' => 'Олена Коваль',
            ])
            ->assertRedirect(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(1, ScheduledClass::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_quick_booking_json_rejects_anytime_room_rental_without_customer_details(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'rental_mode' => 'anytime',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'starts_at' => '2026-06-22T14:00',
                'ends_at' => '2026-06-22T15:00',
            ]);

        $this->assertSame(422, $response->status(), $response->getContent());
        $this->assertSame(__('app.quick_booking_customer_required'), $response->json('errors.customer_name.0'));
        $this->assertSame(__('app.quick_booking_customer_required'), $response->json('errors.customer_phone.0'));

        $this->assertSame(0, ScheduledClass::whereBelongsTo($account)->count());
        $this->assertSame(0, ClassBooking::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_quick_booking_json_rejects_anytime_room_rental_without_end_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
        ]);
        $customer = Customer::factory()->for($account)->create();

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'rental_mode' => 'anytime',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'starts_at' => '2026-06-22T14:00',
                'customer_id' => $customer->id,
            ]);

        $this->assertSame(422, $response->status(), $response->getContent());
        $this->assertSame(__('app.quick_booking_end_time_required'), $response->json('errors.ends_at.0'));

        $this->assertSame(0, ScheduledClass::whereBelongsTo($account)->count());
        $this->assertSame(0, ClassBooking::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_quick_booking_json_rejects_unavailable_private_lesson_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => '2026-06-22 14:30:00',
                'ends_at' => '2026-06-22 15:30:00',
            ]);

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-22T14:00',
                'customer_id' => $customer->id,
            ]);

        $this->assertSame(422, $response->status(), $response->getContent());
        $this->assertSame(__('app.manual_slot_unavailable'), $response->json('errors.starts_at.0'));

        $this->assertSame(1, ScheduledClass::whereBelongsTo($account)->count());
        $this->assertSame(0, ClassBooking::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_quick_booking_json_rejects_private_lesson_when_customer_is_busy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $otherRoom = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $groupType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        $trainer = Trainer::factory()->for($account)->create();
        $otherTrainer = Trainer::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $existingClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($otherRoom)
            ->for($groupType)
            ->for($otherTrainer)
            ->create([
                'starts_at' => '2026-06-22 14:30:00',
                'ends_at' => '2026-06-22 15:30:00',
            ]);
        ClassBooking::factory()
            ->for($account)
            ->for($existingClass)
            ->for($customer)
            ->create(['status' => ClassBookingStatus::Booked->value]);

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-22T14:00',
                'customer_id' => $customer->id,
            ]);

        $this->assertSame(422, $response->status(), $response->getContent());
        $this->assertSame(__('app.manual_slot_unavailable'), $response->json('errors.starts_at.0'));

        $this->assertSame(1, ScheduledClass::whereBelongsTo($account)->count());
        $this->assertSame(1, ClassBooking::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_quick_booking_allows_private_lesson_when_overlapping_class_is_cancelled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $cancelledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => '2026-06-22 14:30:00',
                'ends_at' => '2026-06-22 15:30:00',
                'status' => ScheduledClassStatus::Cancelled->value,
            ]);
        ClassBooking::factory()
            ->for($account)
            ->for($cancelledClass)
            ->for($customer)
            ->create(['status' => ClassBookingStatus::Booked->value]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-22T14:00',
                'customer_id' => $customer->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', __('app.quick_booking_created'));

        $this->assertSame(2, ScheduledClass::whereBelongsTo($account)->count());
        $this->assertSame(1, ScheduledClass::whereBelongsTo($account)->where('status', ScheduledClassStatus::Scheduled->value)->count());
        $this->assertSame(2, ClassBooking::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_quick_booking_json_rejects_full_group_class_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

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
                'starts_at' => '2026-06-22 14:00:00',
                'ends_at' => '2026-06-22 15:00:00',
                'capacity' => 1,
            ]);
        ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for(Customer::factory()->for($account))
            ->create(['status' => ClassBookingStatus::Booked->value]);
        $customer = Customer::factory()->for($account)->create();

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
                'customer_id' => $customer->id,
            ]);

        $this->assertSame(422, $response->status(), $response->getContent());
        $this->assertSame(__('app.no_available_group_slots'), $response->json('errors.scheduled_class_id.0'));

        $this->assertSame(1, ClassBooking::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_owner_can_quick_book_anytime_room_rental_without_linking_matching_rental_pass(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-23 15:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'default_currency' => 'UAH',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Small Hall']);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Rental',
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
            'default_capacity' => 1,
        ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Rental Client']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'name' => 'Rental Pass',
            'sessions_count' => 5,
            'total_validity_days' => 60,
        ]);
        $plan->classTypes()->sync([$classType->id]);
        $plan->rooms()->sync([$room->id]);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute(
            $account,
            $customer,
            $plan,
            purchasedAt: Carbon::parse('2026-06-01 10:00:00', 'UTC'),
        );

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'rental_mode' => 'anytime',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'starts_at' => '2026-06-22T14:10',
                'ends_at' => '2026-06-22T15:40',
                'customer_id' => $customer->id,
                'payment_amount' => '550.25',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', __('app.quick_booking_created'));

        $scheduledClass = ScheduledClass::whereBelongsTo($account)->where('title', 'Rental')->firstOrFail();
        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();
        $payment = CustomerPurchase::whereBelongsTo($account)->where('class_booking_id', $booking->id)->firstOrFail();

        $this->assertSame($scheduledClass->id, $booking->scheduled_class_id);
        $this->assertTrue($booking->skip_class_pass_reservation);
        $this->assertSame('anytime', $scheduledClass->metadata['rental_mode']);
        $this->assertSame(90, (int) $scheduledClass->starts_at->diffInMinutes($scheduledClass->ends_at));
        $this->assertSame(0, $booking->classPassReservation()->count());
        $this->assertSame(0, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(CustomerPurchase::SourceManualCashBooking, $payment->payment_source);
        $this->assertSame(55025, $payment->amount_cents);
        $this->assertNull($payment->class_pass_plan_id);
        $this->assertNull($payment->customer_class_pass_id);

        Carbon::setTestNow();
    }

    public function test_quick_booking_json_creates_anytime_room_rental_and_requests_reload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-23 15:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'default_currency' => 'UAH',
            'opening_hours' => [
                2 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Direct rental',
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
        ]);
        $customer = Customer::factory()->for($account)->create();

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'rental_mode' => 'anytime',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'starts_at' => '2026-06-23T14:15',
                'ends_at' => '2026-06-23T15:45',
                'customer_id' => $customer->id,
            ]);

        $this->assertSame(201, $response->status(), $response->getContent());
        $this->assertSame(__('app.quick_booking_created'), $response->json('message'));
        $this->assertTrue($response->json('reload'));

        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();

        $response->assertJsonPath('booking_id', $booking->id)
            ->assertJsonPath('scheduled_class_id', $booking->scheduled_class_id)
            ->assertSessionHas('status', __('app.quick_booking_created'));

        $this->assertTrue($booking->skip_class_pass_reservation);
        $this->assertSame(1, ScheduledClass::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_anytime_room_rental_card_header_uses_actual_range_duration(): void
    {
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $activityDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Room rental']);
        $classType = ClassType::factory()
            ->for($account)
            ->for($activityDirection, 'activityDirection')
            ->create([
                'name' => 'Rental 120 min',
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'default_duration_minutes' => 120,
            ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Rental 120 min',
                'starts_at' => '2026-06-23 10:00:00',
                'ends_at' => '2026-06-23 14:00:00',
                'metadata' => [
                    'source' => 'quick_booking',
                    'schedule_kind' => ScheduleKind::RoomRental->value,
                    'rental_mode' => 'anytime',
                    'skip_class_pass_reservation' => true,
                ],
            ]);

        $scheduledClass->load([
            'location',
            'room',
            'classType.activityDirection',
            'trainer',
            'activeCancellation.effects',
            'classBookings.customer',
            'classBookings.manualCashPayment',
            'classBookings.classPassReservation.customerClassPass.classPassPlan',
        ]);

        $displayTitle = __('app.room_rental_duration_title', ['minutes' => 240]);

        $this->assertSame($displayTitle, $scheduledClass->displayTitle());
        $this->assertSame(['Room rental'], $scheduledClass->displayTypeLabels());

        $this->view('scheduled-classes._card', [
            'account' => $account,
            'scheduledClass' => $scheduledClass,
            'customerSearchUrl' => '#',
            'bookingStatuses' => ClassBookingStatus::cases(),
            'readonly' => true,
        ])
            ->assertSee('10:00 - 14:00')
            ->assertSee($displayTitle)
            ->assertDontSee('Rental 120 min')
            ->assertDontSee(__('app.booked_capacity'));
    }

    public function test_group_class_card_shows_active_bookings_over_capacity(): void
    {
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $groupType = ClassType::factory()->for($account)->create([
            'name' => 'Pole Group',
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($groupType)
            ->create([
                'title' => 'Pole Group',
                'starts_at' => '2026-06-23 10:00:00',
                'ends_at' => '2026-06-23 11:00:00',
                'capacity' => 4,
            ]);

        foreach ([ClassBookingStatus::Booked, ClassBookingStatus::Attended, ClassBookingStatus::Cancelled, ClassBookingStatus::NoShow] as $status) {
            ClassBooking::factory()
                ->for($account)
                ->for($scheduledClass)
                ->for(Customer::factory()->for($account))
                ->create(['status' => $status->value]);
        }

        ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for(Customer::factory()->for($account))
            ->create([
                'status' => ClassBookingStatus::Booked->value,
                'corrected_removed_at' => now(),
            ]);

        $scheduledClass->load([
            'location',
            'room',
            'classType.activityDirection',
            'trainer',
            'activeCancellation.effects',
            'classBookings.customer',
            'classBookings.manualCashPayment',
            'classBookings.classPassReservation.customerClassPass.classPassPlan',
        ]);

        $this->view('scheduled-classes._card', [
            'account' => $account,
            'scheduledClass' => $scheduledClass,
            'customerSearchUrl' => '#',
            'bookingStatuses' => ClassBookingStatus::cases(),
            'readonly' => true,
        ])
            ->assertSee(__('app.booked_capacity'))
            ->assertSee(__('app.booked_of_capacity', ['booked' => 2, 'capacity' => 4]))
            ->assertDontSee(__('app.booked_of_capacity', ['booked' => 5, 'capacity' => 4]));
    }

    public function test_anytime_room_rental_rejects_ranges_that_overlap_existing_room_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
        ]);
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'starts_at' => '2026-06-22 14:00:00',
                'ends_at' => '2026-06-22 15:00:00',
            ]);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.scheduled-classes.index', $account))
            ->post(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'rental_mode' => 'anytime',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'starts_at' => '2026-06-22T14:30',
                'ends_at' => '2026-06-22T15:30',
                'customer_phone' => '+380671112244',
                'customer_name' => 'Overlap Client',
            ])
            ->assertRedirect(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(1, ScheduledClass::whereBelongsTo($account)->count());
        $this->assertSame(0, ClassBooking::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_anytime_room_rental_rejects_ranges_when_customer_is_busy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $otherRoom = Room::factory()->for($account)->for($location)->create();
        $rentalType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
        ]);
        $groupType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        $customer = Customer::factory()->for($account)->create();
        $existingClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($otherRoom)
            ->for($groupType)
            ->create([
                'starts_at' => '2026-06-22 14:00:00',
                'ends_at' => '2026-06-22 15:00:00',
            ]);
        ClassBooking::factory()
            ->for($account)
            ->for($existingClass)
            ->for($customer)
            ->create(['status' => ClassBookingStatus::Booked->value]);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.scheduled-classes.index', $account))
            ->post(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'rental_mode' => 'anytime',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $rentalType->id,
                'starts_at' => '2026-06-22T14:30',
                'ends_at' => '2026-06-22T15:30',
                'customer_id' => $customer->id,
            ])
            ->assertRedirect(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(1, ScheduledClass::whereBelongsTo($account)->count());
        $this->assertSame(1, ClassBooking::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_private_timeframe_availability_allows_slot_when_one_room_is_free(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        [$owner, $account, $location, $bigRoom, $smallRoom, $classType, $trainer] = $this->privateTimeframeSetup();
        $otherTrainer = Trainer::factory()->for($account)->create();
        $groupType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);

        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($bigRoom)
            ->for($groupType)
            ->for($otherTrainer)
            ->create([
                'starts_at' => '2026-06-22 12:00:00',
                'ends_at' => '2026-06-22 13:00:00',
            ]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.trainers.private-timeframes.toggle', [$account, $trainer]), [
                'location_id' => $location->id,
                'starts_at' => '2026-06-22T12:00',
                'selected' => true,
            ])
            ->assertOk()
            ->assertJsonPath('selected', true);

        $this->createTrainerTimeframes($account, $trainer, $location, ['12:30']);

        $response = $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.time', '12:00');

        $this->assertSame([['id' => $smallRoom->id, 'name' => $smallRoom->name]], $response->json('data.0.rooms'));

        Carbon::setTestNow();
    }

    public function test_private_timeframe_availability_requires_a_room_free_for_exact_lesson_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        [$owner, $account, $location, $bigRoom, $smallRoom, $classType, $trainer] = $this->privateTimeframeSetup();
        $otherTrainer = Trainer::factory()->for($account)->create();
        $groupType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        $this->createTrainerTimeframes($account, $trainer, $location, ['12:00', '12:30', '13:00', '13:30']);

        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($bigRoom)
            ->for($groupType)
            ->for($otherTrainer)
            ->create([
                'starts_at' => '2026-06-22 12:00:00',
                'ends_at' => '2026-06-22 13:00:00',
            ]);
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($smallRoom)
            ->for($groupType)
            ->for($otherTrainer)
            ->create([
                'starts_at' => '2026-06-22 12:30:00',
                'ends_at' => '2026-06-22 14:00:00',
            ]);

        $response = $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
            ]))
            ->assertOk();

        $this->assertSame(['13:00'], array_column($response->json('data'), 'time'));
        $this->assertSame([['id' => $bigRoom->id, 'name' => $bigRoom->name]], $response->json('data.0.rooms'));

        Carbon::setTestNow();
    }

    public function test_strict_private_timeframe_booking_uses_selected_non_first_room(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        [$owner, $account, $location, , $smallRoom, $classType, $trainer] = $this->privateTimeframeSetup();
        $customer = Customer::factory()->for($account)->create();
        $this->createTrainerTimeframes($account, $trainer, $location, ['12:00', '12:30']);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'location_id' => $location->id,
                'room_id' => $smallRoom->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-22T12:00',
                'customer_id' => $customer->id,
            ])
            ->assertCreated();

        $scheduledClass = ScheduledClass::whereBelongsTo($account)
            ->where('title', $classType->name)
            ->firstOrFail();

        $this->assertSame($smallRoom->id, $scheduledClass->room_id);
        $this->assertSame('2026-06-22 12:00:00', $scheduledClass->starts_at->format('Y-m-d H:i:s'));
        $this->assertFalse((bool) ($scheduledClass->metadata['trainer_timeframe_override'] ?? false));

        Carbon::setTestNow();
    }

    public function test_quick_booking_private_timeframes_restrict_by_default_and_override_records_metadata(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        [$owner, $account, $location, $bigRoom, , $classType, $trainer] = $this->privateTimeframeSetup();
        $customer = Customer::factory()->for($account)->create();

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'location_id' => $location->id,
                'room_id' => $bigRoom->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-22T12:00',
                'customer_id' => $customer->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.starts_at.0', __('app.manual_slot_unavailable'));

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'room_id' => $bigRoom->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'ignore_trainer_timeframes' => true,
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.time', '12:00');

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'location_id' => $location->id,
                'room_id' => $bigRoom->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-22T12:00',
                'customer_id' => $customer->id,
                'ignore_trainer_timeframes' => true,
            ])
            ->assertCreated();

        $scheduledClass = ScheduledClass::whereBelongsTo($account)->where('title', $classType->name)->firstOrFail();

        $this->assertTrue($scheduledClass->metadata['trainer_timeframe_override']);

        Carbon::setTestNow();
    }

    public function test_quick_booking_private_lesson_availability_filters_trainers_by_activity_direction(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '12:00', 'closes_at' => '14:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $poleDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Pole']);
        $exoticDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Exotic']);
        $classType = ClassType::factory()->for($account)->create([
            'activity_direction_id' => null,
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $poleTrainer = Trainer::factory()->for($account)->create(['name' => 'Pole Trainer']);
        $exoticTrainer = Trainer::factory()->for($account)->create(['name' => 'Exotic Trainer']);
        $anyDirectionTrainer = Trainer::factory()->for($account)->create(['name' => 'Any Trainer']);
        $poleTrainer->activityDirections()->sync([$poleDirection->id => ['account_id' => $account->id]]);
        $exoticTrainer->activityDirections()->sync([$exoticDirection->id => ['account_id' => $account->id]]);

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $poleTrainer->id,
                'activity_direction_id' => $poleDirection->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.time', '12:00');

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $exoticTrainer->id,
                'activity_direction_id' => $poleDirection->id,
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.quick-bookings.manual-availability', [
                'account' => $account,
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-22',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $anyDirectionTrainer->id,
                'activity_direction_id' => $poleDirection->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.time', '12:00');

        Carbon::setTestNow();
    }

    public function test_quick_booking_trainer_timeframe_override_does_not_bypass_activity_direction(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00', 'UTC'));

        [$owner, $account, $location, $bigRoom, , $classType, $trainer] = $this->privateTimeframeSetup();
        $customer = Customer::factory()->for($account)->create();
        $poleDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Pole']);
        $exoticDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Exotic']);
        $classType->forceFill(['activity_direction_id' => null])->save();
        $trainer->activityDirections()->sync([$exoticDirection->id => ['account_id' => $account->id]]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.quick-bookings.store', $account), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'location_id' => $location->id,
                'room_id' => $bigRoom->id,
                'class_type_id' => $classType->id,
                'activity_direction_id' => $poleDirection->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-22T12:00',
                'customer_id' => $customer->id,
                'ignore_trainer_timeframes' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.trainer_id.0', __('app.trainer_activity_direction_mismatch'));

        $this->assertSame(0, ScheduledClass::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    /**
     * @return array{0: User, 1: Account, 2: Location, 3: Room, 4: Room, 5: ClassType, 6: Trainer}
     */
    private function privateTimeframeSetup(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'trainer_private_timeframes_enabled' => true,
            'opening_hours' => [
                1 => ['enabled' => true, 'opens_at' => '12:00', 'closes_at' => '14:00'],
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $bigRoom = Room::factory()->for($account)->for($location)->create(['name' => 'Big Hall']);
        $smallRoom = Room::factory()->for($account)->for($location)->create(['name' => 'Small Hall']);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Private 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
            'default_capacity' => 1,
        ]);
        $trainer = Trainer::factory()->for($account)->create();

        return [$owner, $account, $location, $bigRoom, $smallRoom, $classType, $trainer];
    }

    /**
     * @param  array<int, string>  $times
     */
    private function createTrainerTimeframes(Account $account, Trainer $trainer, Location $location, array $times): void
    {
        foreach ($times as $time) {
            $startsAt = Carbon::parse('2026-06-22 '.$time.':00', 'UTC');

            TrainerPrivateTimeframe::factory()->create([
                'account_id' => $account->id,
                'trainer_id' => $trainer->id,
                'location_id' => $location->id,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(30),
            ]);
        }
    }
}
