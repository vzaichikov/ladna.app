<?php

namespace Tests\Feature;

use App\Actions\GenerateScheduleOccurrences;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduleSeries;
use App\Models\Trainer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduleSeriesGenerationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_weekly_single_day_series_generates_two_weeks_of_classes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'Europe/Kyiv'));

        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $location = Location::factory()->for($account)->create(['timezone' => 'Europe/Kyiv']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 11]);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Exotic Flow',
            'schedule_kind' => 'group_class',
            'default_duration_minutes' => 60,
            'booking_cutoff_minutes' => 180,
            'cancellation_cutoff_minutes' => 1440,
            'default_capacity' => 10,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        $series = ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->for($trainer)->create([
            'weekday' => now('Europe/Kyiv')->isoWeekday(),
            'start_time' => '14:00',
            'start_date' => now('Europe/Kyiv')->toDateString(),
            'duration_minutes' => 90,
            'booking_cutoff_minutes' => 60,
            'cancellation_cutoff_minutes' => 720,
        ]);

        $created = app(GenerateScheduleOccurrences::class)->execute($series);

        $this->assertSame(3, $created);
        $firstClass = $series->scheduledClasses()->orderBy('starts_at')->firstOrFail();
        $this->assertSame($room->id, $firstClass->room_id);
        $this->assertSame($classType->id, $firstClass->class_type_id);
        $this->assertSame($trainer->id, $firstClass->trainer_id);
        $this->assertSame(90, $firstClass->durationMinutes());
        $this->assertSame(60, $firstClass->booking_cutoff_minutes);
        $this->assertSame(720, $firstClass->cancellation_cutoff_minutes);
        $this->assertSame(10, $firstClass->capacity);
        $this->assertSame('2026-07-01', $series->fresh()->generated_until->toDateString());

        Carbon::setTestNow();
    }

    public function test_class_type_defaults_are_used_when_series_has_no_override(): void
    {
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 8]);
        $classType = ClassType::factory()->for($account)->create([
            'default_duration_minutes' => 75,
            'booking_cutoff_minutes' => 240,
            'cancellation_cutoff_minutes' => 1440,
            'default_capacity' => null,
        ]);
        $series = ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'weekday' => now()->isoWeekday(),
            'duration_minutes' => null,
            'booking_cutoff_minutes' => null,
            'cancellation_cutoff_minutes' => null,
            'capacity' => null,
        ]);

        app(GenerateScheduleOccurrences::class)->execute($series);

        $firstClass = $series->scheduledClasses()->firstOrFail();
        $this->assertSame(75, $firstClass->durationMinutes());
        $this->assertSame(240, $firstClass->booking_cutoff_minutes);
        $this->assertSame(1440, $firstClass->cancellation_cutoff_minutes);
        $this->assertSame(8, $firstClass->capacity);
    }

    public function test_schedule_generate_is_registered_in_laravel_scheduler(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('schedule:generate', Artisan::output());
    }

    public function test_schedule_generate_command_processes_active_series_across_accounts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'Europe/Kyiv'));

        $firstSeries = $this->seriesForAccount(Account::factory()->create(['timezone' => 'Europe/Kyiv']));
        $secondSeries = $this->seriesForAccount(Account::factory()->create(['timezone' => 'Europe/Kyiv']));

        $this->artisan('schedule:generate')->assertSuccessful();

        $this->assertSame(3, $firstSeries->scheduledClasses()->count());
        $this->assertSame(3, $secondSeries->scheduledClasses()->count());

        Carbon::setTestNow();
    }

    private function seriesForAccount(Account $account): ScheduleSeries
    {
        $location = Location::factory()->for($account)->create(['timezone' => 'Europe/Kyiv']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => 'group_class',
            'default_duration_minutes' => 60,
        ]);

        return ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'weekday' => now('Europe/Kyiv')->isoWeekday(),
            'start_time' => '14:00',
            'start_date' => now('Europe/Kyiv')->toDateString(),
        ]);
    }
}
