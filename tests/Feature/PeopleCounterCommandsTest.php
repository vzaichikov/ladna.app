<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\PeopleCounterSample;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassPeopleCount;
use App\Models\Trainer;
use App\Models\UnknownPresenceInterval;
use App\Support\PeopleCounter\PeopleCounterCaptureResult;
use App\Support\PeopleCounter\PeopleCounterCaptureService;
use App\Support\PeopleCounter\PeopleCounterClient;
use App\Support\PeopleCounter\PeopleCounterDetectionResult;
use App\Support\PeopleCounter\PeopleCounterFrameCapture;
use App\Support\PeopleCounter\PeopleCounterSummarizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PeopleCounterCommandsTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_capture_command_records_successful_people_counter_samples(): void
    {
        Carbon::setTestNow('2026-07-04 12:30:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture();
        $this->bindDetector(count: 4);
        $scheduledClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 12:00:00'),
            endsAt: Carbon::parse('2026-07-04 13:00:00'),
        );

        $this->artisan('people-counter:capture', ['--debug' => true])
            ->expectsOutputToContain('[people-counter] capture.selection')
            ->expectsOutputToContain('[people-counter] capture.class.started')
            ->expectsOutputToContain('[people-counter] capture.class.succeeded')
            ->assertExitCode(0);

        $sample = PeopleCounterSample::query()->whereBelongsTo($scheduledClass)->firstOrFail();

        $this->assertSame(PeopleCounterSample::StatusSucceeded, $sample->status);
        $this->assertSame(4, $sample->detected_count);
        $this->assertSame($scheduledClass->account_id, $sample->account_id);
        $this->assertTrue(Storage::disk('local')->exists($sample->original_image_path));
        $this->assertNull($sample->masked_image_path);
    }

    public function test_capture_command_keeps_class_zero_count_without_screenshots(): void
    {
        Carbon::setTestNow('2026-07-04 12:30:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture();
        $this->bindDetector(count: 0);
        $scheduledClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 12:00:00'),
            endsAt: Carbon::parse('2026-07-04 13:00:00'),
        );

        $this->artisan('people-counter:capture')
            ->assertExitCode(0);

        $sample = PeopleCounterSample::query()->whereBelongsTo($scheduledClass)->firstOrFail();

        $this->assertSame(PeopleCounterSample::StatusSucceeded, $sample->status);
        $this->assertSame(0, $sample->detected_count);
        $this->assertNull($sample->original_image_path);
        $this->assertNull($sample->masked_image_path);
    }

    public function test_capture_command_records_camera_failures_without_zero_counts(): void
    {
        Carbon::setTestNow('2026-07-04 12:30:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture(throws: true);
        $scheduledClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 12:00:00'),
            endsAt: Carbon::parse('2026-07-04 13:00:00'),
        );

        $this->artisan('people-counter:capture')
            ->assertExitCode(0);

        $sample = PeopleCounterSample::query()->whereBelongsTo($scheduledClass)->firstOrFail();

        $this->assertSame(PeopleCounterSample::StatusCaptureFailed, $sample->status);
        $this->assertNull($sample->detected_count);
        $this->assertNull($sample->original_image_path);
        $this->assertStringContainsString('Camera offline', (string) $sample->failure_reason);
    }

    public function test_capture_command_records_detection_failures_without_screenshots(): void
    {
        Carbon::setTestNow('2026-07-04 12:30:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture();
        $this->bindDetector(throws: true);
        $scheduledClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 12:00:00'),
            endsAt: Carbon::parse('2026-07-04 13:00:00'),
        );

        $this->artisan('people-counter:capture')
            ->assertExitCode(0);

        $sample = PeopleCounterSample::query()->whereBelongsTo($scheduledClass)->firstOrFail();

        $this->assertSame(PeopleCounterSample::StatusDetectionFailed, $sample->status);
        $this->assertNull($sample->detected_count);
        $this->assertNull($sample->original_image_path);
        $this->assertNull($sample->masked_image_path);
    }

    public function test_capture_command_skips_classes_when_studio_is_closed(): void
    {
        Carbon::setTestNow('2026-07-04 23:30:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture(throws: true);
        $scheduledClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 23:00:00'),
            endsAt: Carbon::parse('2026-07-04 23:59:00'),
        );

        $this->artisan('people-counter:capture', ['--debug' => true])
            ->expectsOutputToContain('[people-counter] capture.selection')
            ->assertExitCode(0);

        $this->assertFalse(PeopleCounterSample::query()->whereBelongsTo($scheduledClass)->exists());
    }

    public function test_capture_command_uses_studio_timezone_for_screenshot_paths_and_opening_hours(): void
    {
        Carbon::setTestNow('2026-07-04 21:30:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture();
        $this->bindDetector(count: 3);
        $scheduledClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 21:00:00'),
            endsAt: Carbon::parse('2026-07-04 22:00:00'),
            accountAttributes: [
                'timezone' => 'Europe/Kyiv',
                'opening_hours' => [
                    6 => ['enabled' => false, 'opens_at' => '00:00', 'closes_at' => '23:59'],
                    7 => ['enabled' => true, 'opens_at' => '00:00', 'closes_at' => '23:59'],
                ],
            ],
            locationAttributes: ['timezone' => 'Europe/Kyiv'],
        );

        $this->artisan('people-counter:capture')
            ->assertExitCode(0);

        $sample = PeopleCounterSample::query()->whereBelongsTo($scheduledClass)->firstOrFail();

        $this->assertStringContainsString('/2026/07/05/20260705003000-original-', $sample->original_image_path);
        $this->assertNull($sample->masked_image_path);
        $this->assertSame('2026-07-04 21:30:00', $sample->captured_at->toDateTimeString());
    }

    public function test_capture_command_uses_two_minute_end_buffer_for_active_classes(): void
    {
        Carbon::setTestNow('2026-07-04 12:58:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture();
        $this->bindDetector(count: 4);
        $includedClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 12:00:00'),
            endsAt: Carbon::parse('2026-07-04 13:00:00'),
        );
        $skippedClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 12:00:00'),
            endsAt: Carbon::parse('2026-07-04 12:59:00'),
        );

        $this->artisan('people-counter:capture')
            ->assertExitCode(0);

        $this->assertTrue(PeopleCounterSample::query()->whereBelongsTo($includedClass)->exists());
        $this->assertFalse(PeopleCounterSample::query()->whereBelongsTo($skippedClass)->exists());
    }

    public function test_capture_command_records_unknown_presence_interval_only_when_people_are_detected(): void
    {
        Carbon::setTestNow('2026-07-04 12:30:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture();
        $this->bindDetector(count: 2);
        $room = $this->peopleCounterRoom();

        $this->artisan('people-counter:capture', ['--debug' => true])
            ->expectsOutputToContain('[people-counter] capture.unknown.selection')
            ->expectsOutputToContain('[people-counter] capture.unknown.started')
            ->expectsOutputToContain('[people-counter] capture.unknown.succeeded')
            ->assertExitCode(0);

        $interval = UnknownPresenceInterval::query()->whereBelongsTo($room)->firstOrFail();
        $sample = PeopleCounterSample::query()->whereBelongsTo($interval, 'unknownPresenceInterval')->firstOrFail();

        $this->assertNull($sample->scheduled_class_id);
        $this->assertSame($room->account_id, $sample->account_id);
        $this->assertSame($room->location_id, $sample->location_id);
        $this->assertSame($room->id, $sample->room_id);
        $this->assertSame(2, $sample->detected_count);
        $this->assertTrue(Storage::disk('local')->exists($sample->original_image_path));
        $this->assertNull($sample->masked_image_path);
        $this->assertSame(1, $interval->sample_count);
        $this->assertSame(2, $interval->peak_detected_count);
    }

    public function test_capture_command_discards_empty_unknown_presence_without_database_rows_or_screenshots(): void
    {
        Carbon::setTestNow('2026-07-04 12:30:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture();
        $this->bindDetector(count: 0);
        $room = $this->peopleCounterRoom();

        $this->artisan('people-counter:capture', ['--debug' => true])
            ->expectsOutputToContain('[people-counter] capture.unknown.empty')
            ->assertExitCode(0);

        $this->assertFalse(PeopleCounterSample::query()->whereBelongsTo($room)->exists());
        $this->assertFalse(UnknownPresenceInterval::query()->whereBelongsTo($room)->exists());
    }

    public function test_capture_command_records_unknown_presence_when_studio_is_closed(): void
    {
        Carbon::setTestNow('2026-07-04 23:30:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture();
        $this->bindDetector(count: 2);
        $room = $this->peopleCounterRoom();

        $this->artisan('people-counter:capture', ['--debug' => true])
            ->expectsOutputToContain('[people-counter] capture.unknown.selection')
            ->expectsOutputToContain('[people-counter] capture.unknown.succeeded')
            ->assertExitCode(0);

        $interval = UnknownPresenceInterval::query()->whereBelongsTo($room)->firstOrFail();
        $sample = PeopleCounterSample::query()->whereBelongsTo($interval, 'unknownPresenceInterval')->firstOrFail();

        $this->assertSame(1, $interval->sample_count);
        $this->assertSame(2, $interval->peak_detected_count);
        $this->assertSame(2, $sample->detected_count);
        $this->assertTrue(Storage::disk('local')->exists($sample->original_image_path));
    }

    public function test_capture_command_skips_unknown_presence_during_class_and_post_class_grace(): void
    {
        Carbon::setTestNow('2026-07-04 11:10:00');
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture();
        $this->bindDetector(count: 3);
        $room = $this->peopleCounterRoom();
        $this->scheduledClassForRoom(
            room: $room,
            startsAt: Carbon::parse('2026-07-04 10:00:00'),
            endsAt: Carbon::parse('2026-07-04 11:00:00'),
        );

        $this->artisan('people-counter:capture', ['--debug' => true])
            ->expectsOutputToContain('"eligible_rooms":0')
            ->assertExitCode(0);

        $this->assertFalse(PeopleCounterSample::query()->whereBelongsTo($room)->exists());
        $this->assertFalse(UnknownPresenceInterval::query()->whereBelongsTo($room)->exists());
    }

    public function test_unknown_presence_samples_extend_intervals_within_merge_gap_and_split_after_gap(): void
    {
        Storage::fake('local');
        $this->fakeMediaMtxGateway();
        $this->bindFrameCapture();
        $this->bindDetector(count: 4);
        $room = $this->peopleCounterRoom();
        $service = app(PeopleCounterCaptureService::class);

        $service->captureUnknownPresence($room, Carbon::parse('2026-07-04 12:00:00'));
        $service->captureUnknownPresence($room, Carbon::parse('2026-07-04 12:10:00'));
        $service->captureUnknownPresence($room, Carbon::parse('2026-07-04 12:26:00'));
        $service->captureUnknownPresence($room, Carbon::parse('2026-07-04 12:57:00'));

        $intervals = UnknownPresenceInterval::query()
            ->whereBelongsTo($room)
            ->orderBy('started_at')
            ->get();

        $this->assertCount(2, $intervals);
        $this->assertSame(3, $intervals[0]->sample_count);
        $this->assertSame('2026-07-04 12:00:00', $intervals[0]->started_at->toDateTimeString());
        $this->assertSame('2026-07-04 12:26:00', $intervals[0]->ended_at->toDateTimeString());
        $this->assertSame(1, $intervals[1]->sample_count);
        $this->assertSame('2026-07-04 12:57:00', $intervals[1]->started_at->toDateTimeString());
    }

    public function test_summarizer_uses_trimmed_75th_percentile_and_adds_trainer_for_group_classes(): void
    {
        Carbon::setTestNow('2026-07-04 11:10:00');
        $scheduledClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 10:00:00'),
            endsAt: Carbon::parse('2026-07-04 11:00:00'),
        );

        foreach ([2, 5, 8, 9] as $index => $count) {
            PeopleCounterSample::factory()->for($scheduledClass)->create([
                'account_id' => $scheduledClass->account_id,
                'location_id' => $scheduledClass->location_id,
                'room_id' => $scheduledClass->room_id,
                'captured_at' => Carbon::parse('2026-07-04 10:05:00')->addMinutes($index * 10),
                'status' => PeopleCounterSample::StatusSucceeded,
                'detected_count' => $count,
            ]);
        }

        PeopleCounterSample::factory()->for($scheduledClass)->create([
            'account_id' => $scheduledClass->account_id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => Carbon::parse('2026-07-04 10:04:00'),
            'status' => PeopleCounterSample::StatusSucceeded,
            'detected_count' => 99,
        ]);
        PeopleCounterSample::factory()->for($scheduledClass)->create([
            'account_id' => $scheduledClass->account_id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => Carbon::parse('2026-07-04 10:56:00'),
            'status' => PeopleCounterSample::StatusSucceeded,
            'detected_count' => 8,
        ]);
        PeopleCounterSample::factory()->for($scheduledClass)->create([
            'account_id' => $scheduledClass->account_id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => Carbon::parse('2026-07-04 10:59:00'),
            'status' => PeopleCounterSample::StatusSucceeded,
            'detected_count' => 99,
        ]);
        PeopleCounterSample::factory()->detectionFailed()->for($scheduledClass)->create([
            'account_id' => $scheduledClass->account_id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => Carbon::parse('2026-07-04 10:35:00'),
        ]);
        ClassBooking::factory()
            ->count(7)
            ->for($scheduledClass)
            ->create([
                'account_id' => $scheduledClass->account_id,
                'status' => ClassBookingStatus::Attended->value,
                'attended_at' => Carbon::parse('2026-07-04 11:00:00'),
            ]);
        ClassBooking::factory()->for($scheduledClass)->create([
            'account_id' => $scheduledClass->account_id,
            'status' => ClassBookingStatus::Attended->value,
            'corrected_removed_at' => Carbon::parse('2026-07-04 11:05:00'),
        ]);

        $debugContext = null;

        app(PeopleCounterSummarizer::class)->summarizeClass(
            $scheduledClass,
            function (string $event, array $context) use (&$debugContext): void {
                if ($event === 'summarize.class.finished') {
                    $debugContext = $context;
                }
            },
        );

        $summary = ScheduledClassPeopleCount::query()->whereBelongsTo($scheduledClass)->firstOrFail();

        $this->assertSame(8, $debugContext['expected_people_count'] ?? null);
        $this->assertSame(1, $debugContext['trainer_adjustment'] ?? null);
        $this->assertSame(ScheduledClassPeopleCount::StatusMatched, $summary->status);
        $this->assertSame(7, $summary->attended_count);
        $this->assertSame(8, $summary->detected_count);
        $this->assertSame(0, $summary->delta);
        $this->assertSame(5, $summary->successful_samples_count);
        $this->assertSame(1, $summary->failed_samples_count);
    }

    public function test_summarizer_does_not_add_trainer_for_non_group_classes(): void
    {
        Carbon::setTestNow('2026-07-04 11:10:00');
        $scheduledClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 10:00:00'),
            endsAt: Carbon::parse('2026-07-04 11:00:00'),
            classTypeAttributes: ['schedule_kind' => ScheduleKind::PrivateLesson->value],
        );

        PeopleCounterSample::factory()->for($scheduledClass)->create([
            'account_id' => $scheduledClass->account_id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => Carbon::parse('2026-07-04 10:30:00'),
            'status' => PeopleCounterSample::StatusSucceeded,
            'detected_count' => 1,
        ]);
        ClassBooking::factory()->for($scheduledClass)->create([
            'account_id' => $scheduledClass->account_id,
            'status' => ClassBookingStatus::Attended->value,
            'attended_at' => Carbon::parse('2026-07-04 11:00:00'),
        ]);

        $debugContext = null;

        app(PeopleCounterSummarizer::class)->summarizeClass(
            $scheduledClass,
            function (string $event, array $context) use (&$debugContext): void {
                if ($event === 'summarize.class.finished') {
                    $debugContext = $context;
                }
            },
        );

        $summary = ScheduledClassPeopleCount::query()->whereBelongsTo($scheduledClass)->firstOrFail();

        $this->assertSame(1, $debugContext['expected_people_count'] ?? null);
        $this->assertSame(0, $debugContext['trainer_adjustment'] ?? null);
        $this->assertSame(ScheduledClassPeopleCount::StatusMatched, $summary->status);
        $this->assertSame(1, $summary->attended_count);
        $this->assertSame(1, $summary->detected_count);
        $this->assertSame(0, $summary->delta);
    }

    public function test_summarize_command_skips_classes_when_studio_is_closed(): void
    {
        Carbon::setTestNow('2026-07-04 23:30:00');
        $scheduledClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-07-04 22:00:00'),
            endsAt: Carbon::parse('2026-07-04 23:00:00'),
        );
        PeopleCounterSample::factory()->for($scheduledClass)->create([
            'account_id' => $scheduledClass->account_id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => Carbon::parse('2026-07-04 22:30:00'),
            'status' => PeopleCounterSample::StatusSucceeded,
            'detected_count' => 4,
        ]);

        $this->artisan('people-counter:summarize', ['--debug' => true])
            ->expectsOutputToContain('[people-counter] summarize.selection')
            ->assertExitCode(0);

        $this->assertFalse(ScheduledClassPeopleCount::query()->whereBelongsTo($scheduledClass)->exists());
    }

    public function test_prune_command_deletes_old_records_and_images(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        Storage::fake('local');
        $scheduledClass = $this->scheduledClass(
            startsAt: Carbon::parse('2026-06-15 10:00:00'),
            endsAt: Carbon::parse('2026-06-15 11:00:00'),
        );
        $room = $scheduledClass->room;
        $oldOriginal = 'people-counter/old/original.jpg';
        $oldMasked = 'people-counter/old/masked.jpg';
        $oldUnknownOriginal = 'people-counter/old/unknown-original.jpg';
        $oldSnapshot = 'people-counter/old/snapshot.jpg';

        Storage::disk('local')->put($oldOriginal, 'old-original');
        Storage::disk('local')->put($oldMasked, 'old-masked');
        Storage::disk('local')->put($oldUnknownOriginal, 'old-unknown-original');
        Storage::disk('local')->put($oldSnapshot, 'old-snapshot');

        $sample = PeopleCounterSample::factory()->for($scheduledClass)->create([
            'account_id' => $scheduledClass->account_id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => Carbon::parse('2026-06-15 10:30:00'),
            'original_image_path' => $oldOriginal,
            'masked_image_path' => $oldMasked,
        ]);
        $summary = ScheduledClassPeopleCount::factory()->for($scheduledClass)->create([
            'account_id' => $scheduledClass->account_id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'trainer_id' => $scheduledClass->trainer_id,
            'summarized_at' => Carbon::parse('2026-06-15 11:05:00'),
        ]);
        $unknownInterval = UnknownPresenceInterval::factory()->for($scheduledClass->account)->for($scheduledClass->location)->for($room)->create([
            'started_at' => Carbon::parse('2026-06-15 12:00:00'),
            'ended_at' => Carbon::parse('2026-06-15 12:07:00'),
            'sample_count' => 1,
            'peak_detected_count' => 2,
        ]);
        $unknownSample = PeopleCounterSample::factory()->for($unknownInterval, 'unknownPresenceInterval')->create([
            'account_id' => $scheduledClass->account_id,
            'scheduled_class_id' => null,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => Carbon::parse('2026-06-15 12:00:00'),
            'original_image_path' => $oldUnknownOriginal,
            'masked_image_path' => null,
        ]);
        $room->update([
            'people_counter_snapshot_path' => $oldSnapshot,
            'people_counter_snapshot_width' => 20,
            'people_counter_snapshot_height' => 20,
            'people_counter_snapshot_taken_at' => Carbon::parse('2026-06-15 09:55:00'),
        ]);

        $this->artisan('people-counter:prune', ['--debug' => true])
            ->expectsOutputToContain('[people-counter] prune.started')
            ->expectsOutputToContain('[people-counter] prune.sample.deleted')
            ->expectsOutputToContain('[people-counter] prune.summaries.deleted')
            ->expectsOutputToContain('[people-counter] prune.unknown_presence_intervals.deleted')
            ->expectsOutputToContain('[people-counter] prune.room_snapshot.deleted')
            ->expectsOutputToContain('[people-counter] prune.finished')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('people_counter_samples', ['id' => $sample->id]);
        $this->assertDatabaseMissing('people_counter_samples', ['id' => $unknownSample->id]);
        $this->assertDatabaseMissing('scheduled_class_people_counts', ['id' => $summary->id]);
        $this->assertDatabaseMissing('unknown_presence_intervals', ['id' => $unknownInterval->id]);
        $this->assertFalse(Storage::disk('local')->exists($oldOriginal));
        $this->assertFalse(Storage::disk('local')->exists($oldMasked));
        $this->assertFalse(Storage::disk('local')->exists($oldUnknownOriginal));
        $this->assertFalse(Storage::disk('local')->exists($oldSnapshot));
        $this->assertNull($room->refresh()->people_counter_snapshot_path);
    }

    private function fakeMediaMtxGateway(): void
    {
        config([
            'services.mediamtx.api_url' => 'http://mediamtx.test',
            'services.mediamtx.public_url' => 'https://cam.example.test',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'http://mediamtx.test/v3/config/paths/get/*' => Http::response([], 404),
            'http://mediamtx.test/v3/config/paths/add/*' => Http::response(['status' => 'ok']),
        ]);
    }

    private function bindFrameCapture(bool $throws = false): void
    {
        $this->app->bind(PeopleCounterFrameCapture::class, fn () => new class($throws) extends PeopleCounterFrameCapture
        {
            public function __construct(private readonly bool $throws) {}

            public function capture(string $pathName, string $storagePath, ?int $captureDelaySeconds = null): PeopleCounterCaptureResult
            {
                if ($this->throws) {
                    throw new RuntimeException('Camera offline.');
                }

                $disk = Storage::disk('local');
                $directory = dirname($storagePath);

                if ($directory !== '.') {
                    $disk->makeDirectory($directory);
                }

                $image = imagecreatetruecolor(20, 20);
                $white = imagecolorallocate($image, 255, 255, 255);
                imagefilledrectangle($image, 0, 0, 19, 19, $white);
                imagejpeg($image, $disk->path($storagePath), 90);
                imagedestroy($image);

                return new PeopleCounterCaptureResult(
                    path: $storagePath,
                    width: 20,
                    height: 20,
                );
            }
        });
    }

    private function bindDetector(int $count = 0, bool $throws = false): void
    {
        $this->app->bind(PeopleCounterClient::class, fn () => new class($count, $throws) extends PeopleCounterClient
        {
            public function __construct(
                private readonly int $count,
                private readonly bool $throws,
            ) {}

            public function count(string $absoluteImagePath): PeopleCounterDetectionResult
            {
                if ($this->throws) {
                    throw new RuntimeException('Detector unavailable.');
                }

                return new PeopleCounterDetectionResult(
                    count: $this->count,
                    detections: [['confidence' => 0.91]],
                    payload: ['count' => $this->count, 'detections' => [['confidence' => 0.91]]],
                    averageConfidence: 0.91,
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $accountAttributes
     * @param  array<string, mixed>  $locationAttributes
     */
    private function peopleCounterRoom(array $accountAttributes = [], array $locationAttributes = []): Room
    {
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
            ...$accountAttributes,
        ]);
        $location = Location::factory()->for($account)->create([
            'timezone' => 'UTC',
            ...$locationAttributes,
        ]);

        return Room::factory()->for($account)->for($location)->create([
            'rtsp_url' => 'rtsp://camera.example.test/live',
            'rtsp_enabled' => true,
            'people_counter_mask_polygons' => [
                [
                    'points' => [
                        ['x' => 0, 'y' => 0],
                        ['x' => 0.2, 'y' => 0],
                        ['x' => 0.2, 'y' => 1],
                        ['x' => 0, 'y' => 1],
                    ],
                ],
            ],
        ]);
    }

    private function scheduledClassForRoom(Room $room, Carbon $startsAt, Carbon $endsAt): ScheduledClass
    {
        $account = $room->account;
        $location = $room->location;
        $direction = ActivityDirection::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->for($direction, 'activityDirection')->create();
        $trainer = Trainer::factory()->for($account)->create();

        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
    }

    /**
     * @param  array<string, mixed>  $accountAttributes
     * @param  array<string, mixed>  $locationAttributes
     * @param  array<string, mixed>  $classTypeAttributes
     */
    private function scheduledClass(
        Carbon $startsAt,
        Carbon $endsAt,
        array $accountAttributes = [],
        array $locationAttributes = [],
        array $classTypeAttributes = [],
    ): ScheduledClass {
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
            ...$accountAttributes,
        ]);
        $location = Location::factory()->for($account)->create([
            'timezone' => 'UTC',
            ...$locationAttributes,
        ]);
        $room = Room::factory()->for($account)->for($location)->create([
            'rtsp_url' => 'rtsp://camera.example.test/live',
            'rtsp_enabled' => true,
            'people_counter_mask_polygons' => [
                [
                    'points' => [
                        ['x' => 0, 'y' => 0],
                        ['x' => 0.2, 'y' => 0],
                        ['x' => 0.2, 'y' => 1],
                        ['x' => 0, 'y' => 1],
                    ],
                ],
            ],
        ]);
        $direction = ActivityDirection::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->for($direction, 'activityDirection')->create($classTypeAttributes);
        $trainer = Trainer::factory()->for($account)->create();

        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
    }
}
