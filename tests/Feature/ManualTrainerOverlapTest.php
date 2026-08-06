<?php

namespace Tests\Feature;

use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Event;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ManualTrainerOverlapTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_manual_trainer_overlap_remains_blocked_by_default_and_cannot_be_forged(): void
    {
        $context = $this->groupClassContext();
        $this->createExistingClass($context, $context['first_room'], $context['trainer']);

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.scheduled-classes.index', $context['account']))
            ->assertOk()
            ->assertDontSee('data-manual-trainer-overlap-confirmation', false);

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account'], ScheduleKind::GroupClass), $this->manualPayload($context))
            ->assertUnprocessable()
            ->assertJsonPath('errors.starts_at.0', __('app.manual_slot_unavailable'));

        $this->postJson($this->storeUrl($context['account'], ScheduleKind::GroupClass), [
            ...$this->manualPayload($context),
            'confirm_trainer_overlap' => '1',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_trainer_overlap');

        $this->assertSame(1, ScheduledClass::whereBelongsTo($context['account'])->count());
    }

    public function test_enabled_manual_trainer_overlap_requires_confirmation_before_creation(): void
    {
        $context = $this->groupClassContext(true);
        $this->createExistingClass($context, $context['first_room'], $context['trainer']);

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.scheduled-classes.index', $context['account']))
            ->assertOk()
            ->assertSee('data-manual-trainer-overlap-confirmation', false);

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account'], ScheduleKind::GroupClass), $this->manualPayload($context))
            ->assertUnprocessable()
            ->assertJsonPath('errors.confirm_trainer_overlap.0', __('app.confirm_trainer_overlap_warning'));

        $response = $this->postJson($this->storeUrl($context['account'], ScheduleKind::GroupClass), [
            ...$this->manualPayload($context),
            'confirm_trainer_overlap' => '1',
        ]);

        $response->assertCreated();

        $createdClass = ScheduledClass::whereBelongsTo($context['account'])
            ->where('room_id', $context['second_room']->id)
            ->firstOrFail();

        $this->assertSame($context['trainer']->id, $createdClass->trainer_id);
        $this->assertTrue($createdClass->metadata['trainer_overlap_confirmed']);
    }

    public function test_trainer_confirmation_never_bypasses_room_occupancy(): void
    {
        $context = $this->groupClassContext(true);
        $this->createExistingClass($context, $context['first_room'], $context['trainer']);
        $payload = $this->manualPayload($context);
        $payload['room_id'] = $context['first_room']->id;
        $payload['confirm_trainer_overlap'] = '1';

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account'], ScheduleKind::GroupClass), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.starts_at.0', __('app.manual_slot_unavailable'));

        $this->assertSame(1, ScheduledClass::whereBelongsTo($context['account'])->count());
    }

    public function test_trainer_confirmation_never_bypasses_a_published_event_room_conflict(): void
    {
        $context = $this->groupClassContext(true);
        $event = Event::factory()->published()->for($context['account'])->create([
            'venue_kind' => 'studio',
            'location_id' => $context['location']->id,
            'starts_at' => Carbon::parse('2026-08-06 15:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-08-06 16:00:00', 'UTC'),
            'timezone' => 'UTC',
        ]);
        $event->rooms()->syncWithPivotValues(
            [$context['second_room']->id],
            ['account_id' => $context['account']->id],
        );

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account'], ScheduleKind::GroupClass), [
                ...$this->manualPayload($context),
                'confirm_trainer_overlap' => '1',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.starts_at.0', __('app.manual_slot_unavailable'));

        $this->assertSame(0, ScheduledClass::whereBelongsTo($context['account'])->count());
    }

    public function test_confirmation_on_an_adjacent_slot_creates_without_overlap_metadata(): void
    {
        $context = $this->groupClassContext(true);
        $this->createExistingClass($context, $context['first_room'], $context['trainer']);
        $payload = $this->manualPayload($context);
        $payload['starts_at'] = '2026-08-06T16:00';
        $payload['confirm_trainer_overlap'] = '1';

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account'], ScheduleKind::GroupClass), $payload)
            ->assertCreated();

        $createdClass = ScheduledClass::whereBelongsTo($context['account'])
            ->where('room_id', $context['second_room']->id)
            ->firstOrFail();

        $this->assertArrayNotHasKey('trainer_overlap_confirmed', $createdClass->metadata);
    }

    public function test_internal_class_can_confirm_an_additional_trainer_overlap(): void
    {
        $context = $this->groupClassContext(true, includeInternalClass: true);
        $busyAdditionalTrainer = Trainer::factory()->for($context['account'])->create();
        $mainTrainer = Trainer::factory()->for($context['account'])->create();
        $internalClassType = ClassType::factory()->for($context['account'])->create([
            'schedule_kind' => ScheduleKind::InternalClass->value,
            'default_duration_minutes' => 60,
        ]);
        $this->createExistingClass($context, $context['first_room'], $busyAdditionalTrainer);
        $payload = [
            'location_id' => $context['location']->id,
            'room_id' => $context['second_room']->id,
            'class_type_id' => $internalClassType->id,
            'trainer_id' => $mainTrainer->id,
            'additional_trainer_ids' => [$busyAdditionalTrainer->id],
            'title' => 'Trainer workshop',
            'starts_at' => '2026-08-06T15:30',
            'duration_minutes' => 60,
        ];

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account'], ScheduleKind::InternalClass), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.confirm_trainer_overlap.0', __('app.confirm_trainer_overlap_warning'));

        $this->postJson($this->storeUrl($context['account'], ScheduleKind::InternalClass), [
            ...$payload,
            'confirm_trainer_overlap' => '1',
        ])->assertCreated();

        $createdClass = ScheduledClass::whereBelongsTo($context['account'])
            ->where('class_type_id', $internalClassType->id)
            ->firstOrFail();

        $this->assertSame($mainTrainer->id, $createdClass->trainer_id);
        $this->assertTrue($createdClass->additionalTrainers()->whereKey($busyAdditionalTrainer->id)->exists());
        $this->assertTrue($createdClass->metadata['trainer_overlap_confirmed']);
    }

    /**
     * @return array{owner: User, account: Account, location: Location, first_room: Room, second_room: Room, class_type: ClassType, trainer: Trainer}
     */
    private function groupClassContext(bool $allowOverlap = false, bool $includeInternalClass = false): array
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $enabledScheduleKinds = [ScheduleKind::GroupClass->value];

        if ($includeInternalClass) {
            $enabledScheduleKinds[] = ScheduleKind::InternalClass->value;
        }

        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'allow_manual_trainer_overlap' => $allowOverlap,
            'enabled_schedule_kinds' => $enabledScheduleKinds,
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $firstRoom = Room::factory()->for($account)->for($location)->create();
        $secondRoom = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create();

        return [
            'owner' => $owner,
            'account' => $account,
            'location' => $location,
            'first_room' => $firstRoom,
            'second_room' => $secondRoom,
            'class_type' => $classType,
            'trainer' => $trainer,
        ];
    }

    /**
     * @param  array{account: Account, location: Location, class_type: ClassType}  $context
     */
    private function createExistingClass(array $context, Room $room, Trainer $trainer): ScheduledClass
    {
        return ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($room)
            ->for($context['class_type'])
            ->for($trainer)
            ->create([
                'starts_at' => Carbon::parse('2026-08-06 15:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-08-06 16:00:00', 'UTC'),
            ]);
    }

    /**
     * @param  array{location: Location, second_room: Room, class_type: ClassType, trainer: Trainer}  $context
     * @return array<string, mixed>
     */
    private function manualPayload(array $context): array
    {
        return [
            'location_id' => $context['location']->id,
            'room_id' => $context['second_room']->id,
            'class_type_id' => $context['class_type']->id,
            'trainer_id' => $context['trainer']->id,
            'starts_at' => '2026-08-06T15:30',
            'duration_minutes' => 60,
        ];
    }

    private function storeUrl(Account $account, ScheduleKind $scheduleKind): string
    {
        return route('dashboard.accounts.scheduled-classes.manual.store', [$account, $scheduleKind->value]);
    }
}
