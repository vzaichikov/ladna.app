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
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicScheduleApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_schedule_api_returns_public_upcoming_classes_shape(): void
    {
        $account = Account::factory()->create(['slug' => 'test-api-studio-nastya', 'timezone' => 'Europe/Kyiv']);
        $location = Location::factory()->for($account)->create(['slug' => 'test-location-1', 'name' => 'Location 1']);
        $room = Room::factory()->for($account)->for($location)->create(['slug' => 'big-hall', 'name' => 'Big Hall']);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Pole Beginner',
            'slug' => 'pole-beginner',
            'color' => '#C7F000',
            'schedule_kind' => 'group_class',
        ]);
        $trainer = Trainer::factory()->for($account)->create([
            'name' => 'Nastya',
            'photo_path' => 'trainer-photos/nastya.png',
        ]);

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
            ->assertJsonPath('data.0.color', '#C7F000')
            ->assertJsonPath('data.0.text_color', '#1E293B')
            ->assertJsonPath('data.0.trainer.name', 'Nastya')
            ->assertJsonPath('data.0.trainer.photo_url', $trainer->photoUrl())
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

    public function test_week_endpoint_returns_the_exact_monday_to_sunday_schedule(): void
    {
        $account = Account::factory()->create(['slug' => 'test-week-api-studio', 'timezone' => 'Europe/Kyiv']);
        $location = Location::factory()->for($account)->create(['slug' => 'main-studio', 'timezone' => 'Europe/Kyiv']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'color' => '#FF008C',
            'schedule_kind' => 'group_class',
        ]);
        $trainer = Trainer::factory()->for($account)->create([
            'name' => 'Slastya',
            'photo_path' => 'trainer-photos/slastya.png',
        ]);

        foreach ([
            ['title' => 'Monday Pole', 'starts_at' => '2026-08-17 09:00'],
            ['title' => 'Sunday Stretching', 'starts_at' => '2026-08-23 18:00'],
            ['title' => 'Following Monday', 'starts_at' => '2026-08-24 09:00'],
        ] as $classData) {
            $startsAt = Carbon::parse($classData['starts_at'], 'Europe/Kyiv')->timezone(config('app.timezone'));

            ScheduledClass::factory()
                ->for($account)
                ->for($location)
                ->for($room)
                ->for($classType)
                ->for($trainer)
                ->create([
                    'title' => $classData['title'],
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addHour(),
                ]);
        }

        $response = $this->getJson('/api/v1/public/test-week-api-studio/main-studio/schedule/week?date=2026-08-19');

        $response->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.timezone', 'Europe/Kyiv')
            ->assertJsonPath('meta.week_start', '2026-08-17')
            ->assertJsonPath('meta.week_end', '2026-08-23')
            ->assertJsonPath('data.0.date', '2026-08-17')
            ->assertJsonPath('data.0.iso_weekday', 1)
            ->assertJsonPath('data.0.classes.0.title', 'Monday Pole')
            ->assertJsonPath('data.0.classes.0.color', '#FF008C')
            ->assertJsonPath('data.0.classes.0.text_color', '#FFFFFF')
            ->assertJsonPath('data.0.classes.0.trainer.name', 'Slastya')
            ->assertJsonPath('data.0.classes.0.trainer.photo_url', $trainer->photoUrl())
            ->assertJsonPath('data.6.date', '2026-08-23')
            ->assertJsonPath('data.6.iso_weekday', 7)
            ->assertJsonPath('data.6.classes.0.title', 'Sunday Stretching')
            ->assertJsonMissing(['title' => 'Following Monday']);
    }

    public function test_week_endpoint_rejects_an_invalid_date(): void
    {
        $account = Account::factory()->create(['slug' => 'test-week-validation-studio']);
        Location::factory()->for($account)->create(['slug' => 'main-studio']);

        $this->getJson('/api/v1/public/test-week-validation-studio/main-studio/schedule/week?date=19-08-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_week_endpoint_defaults_to_the_current_studio_week_without_a_thirty_class_limit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 12:00', 'Europe/Kyiv'));

        try {
            $account = Account::factory()->create(['slug' => 'test-current-week-api-studio', 'timezone' => 'Europe/Kyiv']);
            $location = Location::factory()->for($account)->create(['slug' => 'main-studio']);
            $room = Room::factory()->for($account)->for($location)->create();
            $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
            $startsAt = Carbon::parse('2026-08-20 10:00', 'Europe/Kyiv')->timezone(config('app.timezone'));

            ScheduledClass::factory()
                ->count(31)
                ->for($account)
                ->for($location)
                ->for($room)
                ->for($classType)
                ->create([
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addHour(),
                ]);

            $this->getJson('/api/v1/public/test-current-week-api-studio/main-studio/schedule/week')
                ->assertOk()
                ->assertJsonPath('meta.week_start', '2026-08-17')
                ->assertJsonPath('meta.week_end', '2026-08-23')
                ->assertJsonCount(31, 'data.3.classes');
        } finally {
            Carbon::setTestNow();
        }
    }
}
