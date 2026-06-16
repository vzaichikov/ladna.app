<?php

namespace Tests\Feature;

use App\Actions\GenerateScheduleOccurrences;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Instructor;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduleSeries;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ScheduleSeriesGenerationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_weekly_single_day_series_generates_eight_weeks_of_classes(): void
    {
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $location = Location::factory()->for($account)->create(['timezone' => 'Europe/Kyiv']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 11]);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Exotic Flow',
            'schedule_kind' => 'group_class',
            'default_duration_minutes' => 60,
            'booking_cutoff_minutes' => 180,
            'default_capacity' => 10,
        ]);
        $instructor = Instructor::factory()->for($account)->create();
        $series = ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->for($instructor)->create([
            'weekday' => now('Europe/Kyiv')->isoWeekday(),
            'start_time' => '14:00',
            'start_date' => now('Europe/Kyiv')->toDateString(),
            'duration_minutes' => 90,
            'booking_cutoff_minutes' => 60,
        ]);

        $created = app(GenerateScheduleOccurrences::class)->execute($series);

        $this->assertSame(9, $created);
        $firstClass = $series->scheduledClasses()->orderBy('starts_at')->firstOrFail();
        $this->assertSame($room->id, $firstClass->room_id);
        $this->assertSame($classType->id, $firstClass->class_type_id);
        $this->assertSame($instructor->id, $firstClass->instructor_id);
        $this->assertSame(90, $firstClass->durationMinutes());
        $this->assertSame(60, $firstClass->booking_cutoff_minutes);
        $this->assertSame(10, $firstClass->capacity);
    }

    public function test_class_type_defaults_are_used_when_series_has_no_override(): void
    {
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 8]);
        $classType = ClassType::factory()->for($account)->create([
            'default_duration_minutes' => 75,
            'booking_cutoff_minutes' => 240,
            'default_capacity' => null,
        ]);
        $series = ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'weekday' => now()->isoWeekday(),
            'duration_minutes' => null,
            'booking_cutoff_minutes' => null,
            'capacity' => null,
        ]);

        app(GenerateScheduleOccurrences::class)->execute($series);

        $firstClass = $series->scheduledClasses()->firstOrFail();
        $this->assertSame(75, $firstClass->durationMinutes());
        $this->assertSame(240, $firstClass->booking_cutoff_minutes);
        $this->assertSame(8, $firstClass->capacity);
    }
}
