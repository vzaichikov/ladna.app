<?php

namespace Tests\Feature;

use App\Actions\GenerateScheduleOccurrences;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduleSeries;
use App\Models\Trainer;
use App\Models\User;
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
            'default_duration_minutes' => 90,
            'booking_cutoff_minutes' => 60,
            'cancellation_cutoff_minutes' => 720,
            'default_capacity' => 10,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        $series = ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->for($trainer)->create([
            'weekday' => now('Europe/Kyiv')->isoWeekday(),
            'start_time' => '14:00',
            'start_date' => now('Europe/Kyiv')->toDateString(),
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
        ]);

        app(GenerateScheduleOccurrences::class)->execute($series);

        $firstClass = $series->scheduledClasses()->firstOrFail();
        $this->assertSame(75, $firstClass->durationMinutes());
        $this->assertSame(240, $firstClass->booking_cutoff_minutes);
        $this->assertSame(1440, $firstClass->cancellation_cutoff_minutes);
        $this->assertSame(8, $firstClass->capacity);
    }

    public function test_regeneration_syncs_booked_future_generated_capacity_from_class_type_without_deleting_bookings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $account = Account::factory()->create(['timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 12]);
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'default_capacity' => 8,
        ]);
        $series = ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'weekday' => now('UTC')->isoWeekday(),
            'start_time' => '14:00',
            'start_date' => now('UTC')->toDateString(),
        ]);
        $generator = app(GenerateScheduleOccurrences::class);

        $this->assertSame(3, $generator->execute($series));
        $bookedClass = $series->scheduledClasses()->orderBy('starts_at')->firstOrFail();
        $firstCustomer = Customer::factory()->for($account)->create();
        $secondCustomer = Customer::factory()->for($account)->create();
        ClassBooking::factory()->for($account)->for($bookedClass, 'scheduledClass')->for($firstCustomer)->create([
            'booked_by_user_id' => null,
        ]);
        ClassBooking::factory()->for($account)->for($bookedClass, 'scheduledClass')->for($secondCustomer)->create([
            'booked_by_user_id' => null,
        ]);

        $classType->forceFill(['default_capacity' => 1])->save();

        $this->assertSame(0, $generator->execute($series->fresh()));
        $bookedClass->refresh();

        $this->assertSame(1, $bookedClass->capacity);
        $this->assertSame(2, $bookedClass->classBookings()->count());
        $this->assertSame(3, $series->scheduledClasses()->where('is_generated', true)->count());

        Carbon::setTestNow();
    }

    public function test_regeneration_deletes_only_empty_untouched_stale_generated_classes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $account = Account::factory()->create(['timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $privateClassType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
        ]);
        $rentalClassType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
        ]);
        $series = ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'weekday' => now('UTC')->isoWeekday(),
            'start_time' => '14:00',
            'start_date' => now('UTC')->toDateString(),
        ]);
        $generator = app(GenerateScheduleOccurrences::class);
        $generator->execute($series);
        $generatedClasses = $series->scheduledClasses()->orderBy('starts_at')->get();
        $bookedStaleClass = $generatedClasses->firstOrFail();
        $emptyStaleClass = $generatedClasses->skip(1)->firstOrFail();
        $customer = Customer::factory()->for($account)->create();
        ClassBooking::factory()->for($account)->for($bookedStaleClass, 'scheduledClass')->for($customer)->create([
            'booked_by_user_id' => null,
        ]);
        $manualClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'schedule_series_id' => null,
            'is_generated' => false,
            'starts_at' => '2026-06-18 12:00:00',
            'ends_at' => '2026-06-18 13:00:00',
        ]);
        $privateClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($privateClassType)->create([
            'schedule_series_id' => null,
            'is_generated' => false,
            'starts_at' => '2026-06-18 14:00:00',
            'ends_at' => '2026-06-18 15:00:00',
        ]);
        $rentalClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($rentalClassType)->create([
            'schedule_series_id' => null,
            'is_generated' => false,
            'starts_at' => '2026-06-18 16:00:00',
            'ends_at' => '2026-06-18 17:00:00',
        ]);

        $series->forceFill(['start_time' => '16:00'])->save();

        $this->assertSame(3, $generator->execute($series->refresh()));

        $this->assertNotNull($bookedStaleClass->fresh());
        $this->assertSame(1, $bookedStaleClass->classBookings()->count());
        $this->assertNull($emptyStaleClass->fresh());
        $this->assertModelExists($manualClass);
        $this->assertModelExists($privateClass);
        $this->assertModelExists($rentalClass);

        Carbon::setTestNow();
    }

    public function test_regeneration_preserves_manually_modified_generated_occurrence_without_duplicate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $account = Account::factory()->create(['timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'default_capacity' => 8,
        ]);
        $series = ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'weekday' => now('UTC')->isoWeekday(),
            'start_time' => '14:00',
            'start_date' => now('UTC')->toDateString(),
        ]);
        $generator = app(GenerateScheduleOccurrences::class);
        $generator->execute($series);
        $customClass = $series->scheduledClasses()->orderBy('starts_at')->firstOrFail();
        $customClass->forceFill([
            'capacity' => 5,
            'is_manually_modified' => true,
        ])->save();
        $customStartsAt = $customClass->starts_at->toDateTimeString();

        $classType->forceFill(['default_capacity' => 2])->save();
        $generator->execute($series->fresh());

        $customClass->refresh();
        $syncedClass = $series->scheduledClasses()
            ->whereKeyNot($customClass->id)
            ->orderBy('starts_at')
            ->firstOrFail();

        $this->assertTrue($customClass->is_manually_modified);
        $this->assertSame(5, $customClass->capacity);
        $this->assertSame(1, $series->scheduledClasses()->where('starts_at', $customStartsAt)->count());
        $this->assertSame(2, $syncedClass->capacity);

        Carbon::setTestNow();
    }

    public function test_updating_group_class_regenerates_future_schedule_series_from_class_type_defaults(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 12]);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Pole Flow',
            'slug' => 'pole-flow',
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'default_duration_minutes' => 60,
            'booking_cutoff_minutes' => 120,
            'cancellation_cutoff_minutes' => 1440,
            'default_capacity' => 8,
        ]);
        $series = ScheduleSeries::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'weekday' => now('UTC')->isoWeekday(),
            'start_time' => '14:00',
            'start_date' => now('UTC')->toDateString(),
        ]);

        app(GenerateScheduleOccurrences::class)->execute($series);
        $generatedClass = $series->scheduledClasses()->orderBy('starts_at')->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.group-classes.update', [$account, $classType]), [
                'name' => 'Pole Flow',
                'slug' => 'pole-flow',
                'description' => 'Updated recurring defaults.',
                'color' => '#A78AB9',
                'default_duration_minutes' => 80,
                'booking_cutoff_minutes' => 45,
                'cancellation_cutoff_minutes' => 600,
                'default_capacity' => 6,
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.group-classes.index', $account));

        $generatedClass->refresh();

        $this->assertSame(80, $generatedClass->durationMinutes());
        $this->assertSame(45, $generatedClass->booking_cutoff_minutes);
        $this->assertSame(600, $generatedClass->cancellation_cutoff_minutes);
        $this->assertSame(6, $generatedClass->capacity);

        Carbon::setTestNow();
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
