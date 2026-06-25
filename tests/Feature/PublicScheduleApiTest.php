<?php

namespace Tests\Feature;

use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicScheduleApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_schedule_api_returns_public_upcoming_classes_shape(): void
    {
        $account = Account::factory()->create(['slug' => 'test-api-studio-nastya', 'timezone' => 'Europe/Kyiv']);
        $location = Location::factory()->for($account)->create(['slug' => 'test-location-1', 'name' => 'Location 1']);
        $room = Room::factory()->for($account)->for($location)->create(['slug' => 'big-hall', 'name' => 'Big Hall']);
        $classType = ClassType::factory()->for($account)->create(['name' => 'Pole Beginner', 'slug' => 'pole-beginner', 'schedule_kind' => 'group_class']);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Nastya']);

        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->for($trainer)->create([
            'title' => 'Pole Beginner',
            'description' => 'Introductory pole class',
            'starts_at' => now()->addDay()->setTime(15, 0),
            'ends_at' => now()->addDay()->setTime(16, 0),
            'capacity' => 12,
        ]);
        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->for($trainer)->create([
            'title' => 'Hidden Class',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'is_public' => false,
        ]);
        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->for($trainer)->create([
            'title' => 'Cancelled Class',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => ScheduledClassStatus::Cancelled->value,
        ]);

        $response = $this->getJson('/api/v1/public/test-api-studio-nastya/test-location-1/schedule');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Pole Beginner')
            ->assertJsonPath('data.0.location.slug', 'test-location-1')
            ->assertJsonPath('data.0.room.slug', 'big-hall')
            ->assertJsonPath('data.0.class_type.slug', 'pole-beginner')
            ->assertJsonPath('data.0.schedule_kind', 'group_class')
            ->assertJsonPath('data.0.trainer.name', 'Nastya')
            ->assertJsonPath('data.0.capacity', 12)
            ->assertJsonPath('data.0.available_spots', null)
            ->assertJsonMissing(['title' => 'Hidden Class'])
            ->assertJsonMissing(['title' => 'Cancelled Class']);

        $this->assertStringEndsWith('+03:00', $response->json('data.0.starts_at'));
    }

    public function test_classes_endpoint_alias_returns_same_public_data(): void
    {
        $account = Account::factory()->create(['slug' => 'test-api-studio-oxana']);
        $location = Location::factory()->for($account)->create(['slug' => 'test-main-studio']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);

        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Stretching',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        $this->getJson('/api/v1/public/test-api-studio-oxana/test-main-studio/classes')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Stretching');
    }
}
