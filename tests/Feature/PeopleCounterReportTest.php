<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\PeopleCounterSample;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassPeopleCount;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PeopleCounterReportTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_people_counter_report_card_is_only_on_reports_page_when_enabled(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
        ]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.index', $account))
            ->assertOk()
            ->assertSee(__('app.people_counter_report_title'), false)
            ->assertSee(route('dashboard.accounts.reports.people-counter', $account), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertDontSee(route('dashboard.accounts.reports.people-counter', $account), false);

        $account->update(['enable_people_counter' => false]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.index', $account))
            ->assertOk()
            ->assertDontSee(__('app.people_counter_report_title'), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.people-counter', $account))
            ->assertNotFound();
    }

    public function test_people_counter_report_shows_one_row_per_past_class(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        Storage::fake('local');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
        ]);
        $account->addOwner($owner);
        $scheduledClass = $this->scheduledClass(
            account: $account,
            startsAt: Carbon::parse('2026-07-04 10:00:00'),
            endsAt: Carbon::parse('2026-07-04 11:00:00'),
            title: 'Morning Pole',
        );
        $currentClass = $this->scheduledClass(
            account: $account,
            startsAt: Carbon::parse('2026-07-04 11:30:00'),
            endsAt: Carbon::parse('2026-07-04 12:30:00'),
            title: 'Current Pole',
        );
        $futureClass = $this->scheduledClass(
            account: $account,
            startsAt: Carbon::parse('2026-07-04 13:00:00'),
            endsAt: Carbon::parse('2026-07-04 14:00:00'),
            title: 'Future Pole',
        );
        $originalPath = 'people-counter/testing/original.jpg';
        $maskedPath = 'people-counter/testing/masked.jpg';

        Storage::disk('local')->put($originalPath, 'original');
        Storage::disk('local')->put($maskedPath, 'masked');

        ScheduledClassPeopleCount::factory()->for($scheduledClass)->create([
            'account_id' => $account->id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'trainer_id' => $scheduledClass->trainer_id,
            'status' => ScheduledClassPeopleCount::StatusMismatch,
            'attended_count' => 6,
            'detected_count' => 8,
            'delta' => 2,
            'successful_samples_count' => 4,
            'failed_samples_count' => 1,
        ]);
        $sample = PeopleCounterSample::factory()->for($scheduledClass)->create([
            'account_id' => $account->id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => Carbon::parse('2026-07-04 10:30:00'),
            'detected_count' => 8,
            'original_image_path' => $originalPath,
            'masked_image_path' => $maskedPath,
        ]);
        ClassBooking::factory()
            ->count(2)
            ->for($futureClass)
            ->create([
                'account_id' => $account->id,
                'status' => ClassBookingStatus::Attended->value,
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.people-counter', $account))
            ->assertOk()
            ->assertSee('Morning Pole')
            ->assertSee('Current Pole')
            ->assertDontSee('Future Pole')
            ->assertSee('data-people-counter-row', false)
            ->assertSee('data-class-counts="'.$scheduledClass->id.':6:8:mismatch"', false)
            ->assertSee('data-class-counts="'.$currentClass->id.':0:none:insufficient_data"', false)
            ->assertDontSee('data-class-counts="'.$futureClass->id, false)
            ->assertSee(route('dashboard.accounts.people-counter-samples.image', [$account, $sample, 'original']), false)
            ->assertSee(route('dashboard.accounts.people-counter-samples.image', [$account, $sample, 'masked']), false);
    }

    public function test_people_counter_report_filters_by_location_room_and_trainer(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
        ]);
        $account->addOwner($owner);
        $center = Location::factory()->for($account)->create(['name' => 'Center Studio', 'timezone' => 'UTC']);
        $suburb = Location::factory()->for($account)->create(['name' => 'Suburb Studio', 'timezone' => 'UTC']);
        $centerRoom = Room::factory()->for($account)->for($center)->create(['name' => 'Center Hall']);
        $suburbRoom = Room::factory()->for($account)->for($suburb)->create(['name' => 'Suburb Hall']);
        $centerTrainer = Trainer::factory()->for($account)->create(['name' => 'Center Trainer']);
        $suburbTrainer = Trainer::factory()->for($account)->create(['name' => 'Suburb Trainer']);

        $this->scheduledClass(
            account: $account,
            startsAt: Carbon::parse('2026-07-04 10:00:00'),
            endsAt: Carbon::parse('2026-07-04 11:00:00'),
            title: 'Filtered Pole',
            location: $center,
            room: $centerRoom,
            trainer: $centerTrainer,
        );
        $this->scheduledClass(
            account: $account,
            startsAt: Carbon::parse('2026-07-04 10:00:00'),
            endsAt: Carbon::parse('2026-07-04 11:00:00'),
            title: 'Other Pole',
            location: $suburb,
            room: $suburbRoom,
            trainer: $suburbTrainer,
        );

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.people-counter', ['account' => $account, 'location_id' => $center->id]))
            ->assertOk()
            ->assertSee('Filtered Pole')
            ->assertDontSee('Other Pole');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.people-counter', ['account' => $account, 'room_id' => $centerRoom->id]))
            ->assertOk()
            ->assertSee('Filtered Pole')
            ->assertDontSee('Other Pole');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.people-counter', ['account' => $account, 'trainer_id' => $centerTrainer->id]))
            ->assertOk()
            ->assertSee('Filtered Pole')
            ->assertDontSee('Other Pole');
    }

    public function test_people_counter_report_date_filter_uses_account_timezone_and_includes_current_class(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 21:30:00', 'UTC'));
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'Europe/Kyiv',
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
        ]);
        $account->addOwner($owner);

        $this->scheduledClass(
            account: $account,
            startsAt: Carbon::parse('2026-07-05 00:15:00', 'Europe/Kyiv')->timezone('UTC'),
            endsAt: Carbon::parse('2026-07-05 01:15:00', 'Europe/Kyiv')->timezone('UTC'),
            title: 'Kyiv Current Pole',
        );
        $this->scheduledClass(
            account: $account,
            startsAt: Carbon::parse('2026-07-05 00:45:00', 'Europe/Kyiv')->timezone('UTC'),
            endsAt: Carbon::parse('2026-07-05 01:45:00', 'Europe/Kyiv')->timezone('UTC'),
            title: 'Kyiv Future Pole',
        );
        $this->scheduledClass(
            account: $account,
            startsAt: Carbon::parse('2026-07-04 20:00:00', 'Europe/Kyiv')->timezone('UTC'),
            endsAt: Carbon::parse('2026-07-04 21:00:00', 'Europe/Kyiv')->timezone('UTC'),
            title: 'Kyiv Previous Day Pole',
        );

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.people-counter', ['account' => $account, 'date' => '2026-07-05']))
            ->assertOk()
            ->assertSee('Kyiv Current Pole')
            ->assertDontSee('Kyiv Future Pole')
            ->assertDontSee('Kyiv Previous Day Pole');
    }

    public function test_people_counter_report_requires_report_permission(): void
    {
        $account = Account::factory()->create([
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
        ]);
        $staff = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($staff, 'user')
            ->create([
                'role' => AccountRole::Receptionist->value,
                'permissions' => [],
            ]);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.reports.people-counter', $account))
            ->assertForbidden();
    }

    public function test_owner_can_save_people_counter_mask_for_enabled_room_camera(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create([
            'rtsp_url' => 'rtsp://camera.example.test/live',
            'rtsp_enabled' => true,
            'people_counter_snapshot_path' => 'people-counter/testing/snapshot.jpg',
            'people_counter_snapshot_width' => 20,
            'people_counter_snapshot_height' => 20,
            'people_counter_snapshot_taken_at' => now(),
        ]);

        Storage::disk('local')->put('people-counter/testing/snapshot.jpg', 'snapshot');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.people-counter-mask.edit', [$account, $room]))
            ->assertOk()
            ->assertSee('data-people-counter-mask-editor', false)
            ->assertSee(route('dashboard.accounts.rooms.people-counter-mask.snapshot', [$account, $room]), false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.rooms.people-counter-mask.update', [$account, $room]), [
                'people_counter_mask_polygons' => json_encode([
                    [
                        'points' => [
                            ['x' => 0, 'y' => 0],
                            ['x' => 0.3333334, 'y' => 0],
                            ['x' => 0.3333334, 'y' => 1],
                        ],
                    ],
                ]),
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.people-counter-mask.edit', [$account, $room]));

        $this->assertEquals([
            [
                'points' => [
                    ['x' => 0.0, 'y' => 0.0],
                    ['x' => 0.333333, 'y' => 0.0],
                    ['x' => 0.333333, 'y' => 1.0],
                ],
            ],
        ], $room->refresh()->people_counter_mask_polygons);
    }

    private function scheduledClass(
        Account $account,
        Carbon $startsAt,
        Carbon $endsAt,
        string $title,
        ?Location $location = null,
        ?Room $room = null,
        ?Trainer $trainer = null,
    ): ScheduledClass {
        $location ??= Location::factory()->for($account)->create(['timezone' => $account->timezone ?? 'UTC']);
        $room ??= Room::factory()->for($account)->for($location)->create([
            'rtsp_url' => 'rtsp://camera.example.test/live',
            'rtsp_enabled' => true,
        ]);
        $direction = ActivityDirection::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->for($direction, 'activityDirection')->create();
        $trainer ??= Trainer::factory()->for($account)->create(['name' => 'Report Trainer']);

        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'title' => $title,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
    }
}
