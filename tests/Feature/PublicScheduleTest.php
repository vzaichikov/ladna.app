<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ClassType;
use App\Models\Instructor;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicScheduleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_schedule_page_shows_only_public_classes_for_location(): void
    {
        $account = Account::factory()->create(['slug' => 'test-studio-nastya', 'timezone' => 'Europe/Kyiv']);
        $location = Location::factory()->for($account)->create(['slug' => 'test-location-1', 'name' => 'Location 1']);
        $otherLocation = Location::factory()->for($account)->create(['slug' => 'test-location-2']);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Big Hall']);
        $otherRoom = Room::factory()->for($account)->for($otherLocation)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $instructor = Instructor::factory()->for($account)->create(['name' => 'Nastya']);

        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->for($instructor)->create([
            'title' => 'Pole Beginner',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->for($instructor)->create([
            'title' => 'Private Staff Class',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'is_public' => false,
        ]);
        ScheduledClass::factory()->for($account)->for($otherLocation)->for($otherRoom)->for($classType)->for($instructor)->create([
            'title' => 'Other Location Class',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        $this->get('/test-studio-nastya/test-location-1/schedule')
            ->assertOk()
            ->assertSee('Pole Beginner')
            ->assertSee('Big Hall')
            ->assertDontSee('Private Staff Class')
            ->assertDontSee('Other Location Class');
    }

    public function test_inactive_location_schedule_is_not_public(): void
    {
        $account = Account::factory()->create(['slug' => 'test-studio-inactive']);
        Location::factory()->for($account)->create(['slug' => 'inactive-location', 'is_active' => false]);

        $this->get('/test-studio-inactive/inactive-location/schedule')->assertNotFound();
    }

    public function test_suspended_account_schedule_is_not_public(): void
    {
        $account = Account::factory()->create(['slug' => 'test-suspended-studio', 'status' => 'suspended']);
        Location::factory()->for($account)->create(['slug' => 'main']);

        $this->get('/test-suspended-studio/main/schedule')->assertNotFound();
    }

    public function test_private_lesson_and_room_rental_class_types_are_not_public(): void
    {
        $account = Account::factory()->create(['slug' => 'test-private-kind-studio']);
        $location = Location::factory()->for($account)->create(['slug' => 'main']);
        $room = Room::factory()->for($account)->for($location)->create();
        $privateType = ClassType::factory()->for($account)->create(['schedule_kind' => 'private_lesson']);
        $rentalType = ClassType::factory()->for($account)->create(['schedule_kind' => 'room_rental']);

        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($privateType)->create([
            'title' => 'Private Lesson',
        ]);
        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($rentalType)->create([
            'title' => 'Room Rental',
        ]);

        $this->get('/test-private-kind-studio/main/schedule')
            ->assertOk()
            ->assertDontSee('Private Lesson')
            ->assertDontSee('Room Rental');
    }

    public function test_account_default_language_affects_public_schedule_without_session_locale(): void
    {
        $account = Account::factory()->create([
            'slug' => 'test-english-studio',
            'default_language' => 'en',
        ]);
        Location::factory()->for($account)->create(['slug' => 'main']);

        $this->get('/test-english-studio/main/schedule')
            ->assertOk()
            ->assertSee('No public classes are scheduled yet.');
    }
}
