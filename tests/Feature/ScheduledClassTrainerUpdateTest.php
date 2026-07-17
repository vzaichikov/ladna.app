<?php

namespace Tests\Feature;

use App\Actions\SyncTrainerSubstitutions;
use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\StudioPermission;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\Account;
use App\Models\AccountActivityLog;
use App\Models\AccountMembership;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassPeopleCount;
use App\Models\ScheduledClassTrainerChange;
use App\Models\TelegramAlert;
use App\Models\Trainer;
use App\Models\User;
use App\Support\AccountActivityLogSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduledClassTrainerUpdateTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_assign_trainer_to_future_manual_group_class_and_receives_refreshed_card(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        AccountActivityLogSettings::setEnabled(true);
        $context = $this->context();
        $scheduledClass = $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 10:00:00', hasTrainer: false);
        $olderChange = $scheduledClass->trainerChanges()->create([
            'account_id' => $context['account']->id,
            'previous_trainer_id' => $context['oldTrainer']->id,
            'new_trainer_id' => null,
            'previous_trainer_name' => $context['oldTrainer']->name,
            'new_trainer_name' => null,
            'actor_name' => 'Earlier Owner',
            'actor_role' => AccountRole::Owner->value,
        ]);

        $response = $this->actingAs($context['owner'])
            ->patchJson($this->updateUrl($context['account'], $scheduledClass), [
                'trainer_id' => $context['newTrainer']->id,
                'readonly' => false,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', __('app.scheduled_class_trainer_updated'))
            ->assertJsonPath('scheduled_class_id', $scheduledClass->id);

        $cardHtml = (string) $response->json('card_html');
        $this->assertStringContainsString($context['newTrainer']->name, $cardHtml);
        $this->assertStringContainsString(__('app.trainer_change_history'), $cardHtml);
        $this->assertStringContainsString('data-scheduled-class-trainer-open="'.$scheduledClass->id.'"', $cardHtml);
        $this->assertStringContainsString('name="readonly" value="0"', $cardHtml);

        $scheduledClass->refresh();
        $this->assertSame($context['newTrainer']->id, $scheduledClass->trainer_id);
        $this->assertTrue($scheduledClass->is_manually_modified);
        $this->assertSame(
            $context['newTrainer']->id,
            data_get($scheduledClass->metadata, ScheduledClass::MANUAL_TRAINER_OVERRIDE_METADATA_KEY.'.trainer_id'),
        );

        $change = $scheduledClass->trainerChanges()->firstOrFail();
        $this->assertNull($change->previous_trainer_id);
        $this->assertNull($change->previous_trainer_name);
        $this->assertSame($context['newTrainer']->id, $change->new_trainer_id);
        $this->assertSame($context['newTrainer']->name, $change->new_trainer_name);
        $this->assertSame($context['owner']->id, $change->actor_user_id);
        $this->assertSame($context['owner']->name, $change->actor_name);
        $this->assertSame($context['owner']->email, $change->actor_email);
        $this->assertSame(AccountRole::Owner->value, $change->actor_role);
        $this->assertSame([$change->id, $olderChange->id], $scheduledClass->trainerChanges()->pluck('id')->all());

        $activityLog = AccountActivityLog::query()
            ->whereBelongsTo($context['account'])
            ->where('route_name', 'dashboard.accounts.scheduled-classes.trainer.update')
            ->firstOrFail();
        $this->assertSame('PATCH', $activityLog->method);
        $this->assertSame(ScheduledClass::class, $activityLog->subject_type);
        $this->assertSame($scheduledClass->id, $activityLog->subject_id);
    }

    public function test_staff_with_manage_schedule_can_change_future_manual_private_lesson(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $staff = User::factory()->create();
        AccountMembership::factory()->for($context['account'])->for($staff)->create([
            'role' => AccountRole::Receptionist->value,
            'permissions' => [StudioPermission::ManageSchedule->value],
        ]);
        $scheduledClass = $this->scheduledClass($context, ScheduleKind::PrivateLesson, '2026-07-17 11:00:00');

        $this->actingAs($staff)
            ->patchJson($this->updateUrl($context['account'], $scheduledClass), [
                'trainer_id' => $context['newTrainer']->id,
            ])
            ->assertOk();

        $this->assertSame($context['newTrainer']->id, $scheduledClass->fresh()->trainer_id);
        $change = $scheduledClass->trainerChanges()->firstOrFail();
        $this->assertSame($staff->id, $change->actor_user_id);
        $this->assertSame(AccountRole::Receptionist->value, $change->actor_role);
    }

    public function test_user_without_manage_schedule_is_forbidden(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $staff = User::factory()->create();
        AccountMembership::factory()->for($context['account'])->for($staff)->create([
            'role' => AccountRole::Receptionist->value,
            'permissions' => [],
        ]);
        $scheduledClass = $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 10:00:00');

        $this->actingAs($staff)
            ->patchJson($this->updateUrl($context['account'], $scheduledClass), [
                'trainer_id' => $context['newTrainer']->id,
            ])
            ->assertForbidden();

        $this->assertSame($context['oldTrainer']->id, $scheduledClass->fresh()->trainer_id);
        $this->assertSame(0, $scheduledClass->trainerChanges()->count());
    }

    public function test_cross_studio_class_and_trainer_ids_are_rejected(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $other = $this->context();
        $scheduledClass = $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 10:00:00');
        $otherClass = $this->scheduledClass($other, ScheduleKind::GroupClass, '2026-07-17 10:00:00');

        $this->actingAs($context['owner'])
            ->patchJson($this->updateUrl($context['account'], $otherClass), [
                'trainer_id' => $context['newTrainer']->id,
            ])
            ->assertNotFound();

        $this->actingAs($context['owner'])
            ->patchJson($this->updateUrl($context['account'], $scheduledClass), [
                'trainer_id' => $other['newTrainer']->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trainer_id');

        $this->assertSame($context['oldTrainer']->id, $scheduledClass->fresh()->trainer_id);
        $this->assertSame(0, ScheduledClassTrainerChange::whereBelongsTo($scheduledClass)->count());
    }

    public function test_future_and_running_generated_classes_are_locked(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $future = $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 11:00:00', generated: true);
        $running = $this->scheduledClass(
            $context,
            ScheduleKind::GroupClass,
            '2026-07-17 08:30:00',
            generated: true,
            endsAt: '2026-07-17 09:30:00',
        );

        foreach ([$future, $running] as $scheduledClass) {
            $this->actingAs($context['owner'])
                ->patchJson($this->updateUrl($context['account'], $scheduledClass), [
                    'trainer_id' => $context['newTrainer']->id,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('trainer_id');

            $this->assertSame($context['oldTrainer']->id, $scheduledClass->fresh()->trainer_id);
            $this->assertSame(0, $scheduledClass->trainerChanges()->count());
        }
    }

    public function test_ended_generated_class_accepts_inactive_trainer_and_updates_people_count_snapshot(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $scheduledClass = $this->scheduledClass(
            $context,
            ScheduleKind::GroupClass,
            '2026-07-16 10:00:00',
            generated: true,
        );
        $peopleCount = ScheduledClassPeopleCount::factory()
            ->for($context['account'])
            ->for($scheduledClass)
            ->for($context['location'])
            ->for($context['room'])
            ->for($context['oldTrainer'])
            ->create();
        $this->booking($context, $scheduledClass);

        $response = $this->actingAs($context['owner'])
            ->patchJson($this->updateUrl($context['account'], $scheduledClass), [
                'trainer_id' => $context['inactiveTrainer']->id,
                'readonly' => true,
            ])
            ->assertOk();

        $this->assertStringContainsString('name="readonly" value="1"', (string) $response->json('card_html'));
        $this->assertStringContainsString($context['inactiveTrainer']->name, (string) $response->json('card_html'));

        $scheduledClass->refresh();
        $this->assertSame($context['inactiveTrainer']->id, $scheduledClass->trainer_id);
        $this->assertTrue($scheduledClass->is_manually_modified);
        $this->assertSame($context['inactiveTrainer']->id, $peopleCount->fresh()->trainer_id);

        $change = $scheduledClass->trainerChanges()->firstOrFail();
        $this->assertSame($context['oldTrainer']->id, $change->previous_trainer_id);
        $this->assertSame($context['oldTrainer']->name, $change->previous_trainer_name);
        $this->assertSame($context['inactiveTrainer']->id, $change->new_trainer_id);
        $this->assertSame($context['inactiveTrainer']->name, $change->new_trainer_name);
        $this->assertSame(0, TelegramAlert::query()->whereBelongsTo($scheduledClass, 'scheduledClass')->count());
    }

    public function test_unchanged_room_rental_and_inactive_future_submissions_do_not_create_history(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $unchangedClass = $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 10:00:00');
        $roomRental = $this->scheduledClass($context, ScheduleKind::RoomRental, '2026-07-17 10:00:00');
        $inactiveTrainerClass = $this->scheduledClass($context, ScheduleKind::PrivateLesson, '2026-07-17 10:00:00');

        $submissions = [
            [$unchangedClass, $context['oldTrainer']],
            [$roomRental, $context['newTrainer']],
            [$inactiveTrainerClass, $context['inactiveTrainer']],
        ];

        foreach ($submissions as [$scheduledClass, $trainer]) {
            $originalTrainerId = $scheduledClass->trainer_id;

            $this->actingAs($context['owner'])
                ->patchJson($this->updateUrl($context['account'], $scheduledClass), [
                    'trainer_id' => $trainer->id,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('trainer_id');

            $this->assertSame($originalTrainerId, $scheduledClass->fresh()->trainer_id);
            $this->assertSame(0, $scheduledClass->trainerChanges()->count());
        }
    }

    public function test_pending_assignment_alert_is_retargeted_and_sent_history_is_preserved(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $scheduledClass = $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 10:00:00');
        $booking = $this->booking($context, $scheduledClass);
        $pendingAlert = TelegramAlert::factory()->for($context['account'])->for($context['oldTrainer'])->for($scheduledClass)->for($booking)->create([
            'status' => TelegramAlertStatus::Pending->value,
            'type' => TelegramAlertType::TrainerAssignment->value,
            'dedupe_key' => 'old-pending-key',
            'payload' => ['trainer_name' => $context['oldTrainer']->name],
            'text' => 'Old trainer text',
            'attempts' => 2,
            'next_attempt_at' => now()->addMinute(),
            'last_error' => 'temporary',
        ]);
        $sentAlert = TelegramAlert::factory()->for($context['account'])->for($context['oldTrainer'])->for($scheduledClass)->for($booking)->create([
            'status' => TelegramAlertStatus::Sent->value,
            'type' => TelegramAlertType::TrainerAssignment->value,
            'dedupe_key' => 'old-sent-key',
            'text' => 'Already sent',
            'sent_at' => now()->subMinute(),
        ]);
        $failedAlert = TelegramAlert::factory()->for($context['account'])->for($context['oldTrainer'])->for($scheduledClass)->for($booking)->create([
            'status' => TelegramAlertStatus::Failed->value,
            'type' => TelegramAlertType::TrainerAssignment->value,
            'dedupe_key' => 'old-failed-key',
            'text' => 'Already failed',
            'failed_at' => now()->subMinute(),
            'last_error' => 'delivery_failed',
        ]);

        $this->actingAs($context['owner'])
            ->patchJson($this->updateUrl($context['account'], $scheduledClass), [
                'trainer_id' => $context['newTrainer']->id,
            ])
            ->assertOk();

        $pendingAlert->refresh();
        $this->assertSame(TelegramAlertStatus::Pending, $pendingAlert->status);
        $this->assertSame($context['newTrainer']->id, $pendingAlert->trainer_id);
        $this->assertSame($context['newTrainer']->name, $pendingAlert->payload['trainer_name']);
        $this->assertStringContainsString($context['newTrainer']->name, $pendingAlert->text);
        $this->assertStringContainsString('trainer-change:', (string) $pendingAlert->dedupe_key);
        $this->assertSame(0, $pendingAlert->attempts);
        $this->assertNull($pendingAlert->next_attempt_at);
        $this->assertNull($pendingAlert->last_error);

        $sentAlert->refresh();
        $this->assertSame(TelegramAlertStatus::Sent, $sentAlert->status);
        $this->assertSame($context['oldTrainer']->id, $sentAlert->trainer_id);
        $this->assertSame('Already sent', $sentAlert->text);

        $failedAlert->refresh();
        $this->assertSame(TelegramAlertStatus::Failed, $failedAlert->status);
        $this->assertSame($context['oldTrainer']->id, $failedAlert->trainer_id);
        $this->assertSame('Already failed', $failedAlert->text);
    }

    public function test_new_assignment_alert_is_queued_only_when_future_class_has_active_booking(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $bookedClass = $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 10:00:00');
        $bookedClassBooking = $this->booking($context, $bookedClass);
        $existingSentAlert = TelegramAlert::factory()
            ->for($context['account'])
            ->for($context['oldTrainer'])
            ->for($bookedClass)
            ->for($bookedClassBooking)
            ->create([
                'status' => TelegramAlertStatus::Sent->value,
                'dedupe_key' => 'already-sent-assignment',
                'sent_at' => now()->subMinute(),
            ]);
        $emptyClass = $this->scheduledClass($context, ScheduleKind::PrivateLesson, '2026-07-17 12:00:00');
        $cancelledClass = $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 14:00:00');
        $cancelledClass->forceFill(['status' => ScheduledClassStatus::Cancelled->value])->save();
        $this->booking($context, $cancelledClass);

        $this->actingAs($context['owner'])
            ->patchJson($this->updateUrl($context['account'], $bookedClass), [
                'trainer_id' => $context['newTrainer']->id,
            ])
            ->assertOk();

        $newAlert = TelegramAlert::query()
            ->whereBelongsTo($bookedClass, 'scheduledClass')
            ->where('status', TelegramAlertStatus::Pending->value)
            ->firstOrFail();
        $this->assertSame($context['newTrainer']->id, $newAlert->trainer_id);
        $this->assertStringContainsString('trainer-change:', (string) $newAlert->dedupe_key);
        $this->assertSame(TelegramAlertStatus::Sent, $existingSentAlert->fresh()->status);
        $this->assertSame($context['oldTrainer']->id, $existingSentAlert->fresh()->trainer_id);

        $orphanedPendingAlert = TelegramAlert::factory()
            ->for($context['account'])
            ->for($context['oldTrainer'])
            ->for($emptyClass)
            ->create([
                'status' => TelegramAlertStatus::Pending->value,
                'dedupe_key' => 'orphaned-pending-assignment',
            ]);

        $this->actingAs($context['owner'])
            ->patchJson($this->updateUrl($context['account'], $emptyClass), [
                'trainer_id' => $context['newTrainer']->id,
            ])
            ->assertOk();

        $this->assertSame(1, TelegramAlert::query()->whereBelongsTo($emptyClass, 'scheduledClass')->count());
        $this->assertSame(TelegramAlertStatus::Failed, $orphanedPendingAlert->fresh()->status);
        $this->assertSame('trainer_reassigned', $orphanedPendingAlert->fresh()->last_error);

        $this->actingAs($context['owner'])
            ->patchJson($this->updateUrl($context['account'], $cancelledClass), [
                'trainer_id' => $context['newTrainer']->id,
            ])
            ->assertOk();

        $this->assertSame(0, TelegramAlert::query()->whereBelongsTo($cancelledClass, 'scheduledClass')->count());
    }

    public function test_current_and_history_cards_show_control_only_when_authorized_and_eligible(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $manualClass = $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 10:00:00', hasTrainer: false);
        $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 12:00:00', generated: true);

        $currentResponse = $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.scheduled-classes.index', [$context['account'], 'tab' => 'today']))
            ->assertOk();

        $this->assertSame(1, substr_count($currentResponse->getContent(), 'data-scheduled-class-trainer-open='));
        $currentResponse->assertSee('data-scheduled-class-trainer-open="'.$manualClass->id.'"', false);

        $pastClass = $this->scheduledClass(
            $context,
            ScheduleKind::GroupClass,
            '2026-07-16 10:00:00',
            generated: true,
        );
        $historyResponse = $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.scheduled-classes-history.index', [
                $context['account'],
                'date_from' => '2026-07-16',
                'date_to' => '2026-07-16',
            ]))
            ->assertOk()
            ->assertSee('data-scheduled-class-trainer-open="'.$pastClass->id.'"', false)
            ->assertSee('name="readonly" value="1"', false)
            ->assertSee($context['inactiveTrainer']->name);

        $staff = User::factory()->create();
        AccountMembership::factory()->for($context['account'])->for($staff)->create([
            'role' => AccountRole::Receptionist->value,
            'permissions' => [],
        ]);

        $this->actingAs($staff)
            ->get(route('dashboard.accounts.scheduled-classes-history.index', [
                $context['account'],
                'date_from' => '2026-07-16',
                'date_to' => '2026-07-16',
            ]))
            ->assertOk()
            ->assertDontSee('data-scheduled-class-trainer-open=', false);

        $this->assertStringContainsString('data-scheduled-class-trainer-modal', $historyResponse->getContent());
    }

    public function test_manual_correction_is_not_undone_by_substitution_resync(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $substitute = Trainer::factory()->for($context['account'])->create(['name' => 'Substitution Trainer']);
        $scheduledClass = $this->scheduledClass(
            $context,
            ScheduleKind::GroupClass,
            '2026-07-16 10:00:00',
            generated: true,
        );
        $substitution = $context['account']->trainerSubstitutions()->create([
            'replaced_trainer_id' => $context['oldTrainer']->id,
            'substitute_trainer_id' => $substitute->id,
            'location_id' => $context['location']->id,
            'room_id' => $context['room']->id,
            'mode' => 'classes',
            'date_from' => '2026-07-16',
            'date_to' => '2026-07-16',
            'scheduled_class_ids' => [$scheduledClass->id],
            'replaced_trainer_name' => $context['oldTrainer']->name,
            'substitute_trainer_name' => $substitute->name,
            'location_name' => $context['location']->name,
            'room_name' => $context['room']->name,
        ]);
        $scheduledClass->forceFill([
            'trainer_id' => $substitute->id,
            'metadata' => [
                SyncTrainerSubstitutions::MetadataKey => [
                    'id' => $substitution->id,
                    'original_trainer_id' => $context['oldTrainer']->id,
                ],
            ],
        ])->save();

        $this->actingAs($context['owner'])
            ->patchJson($this->updateUrl($context['account'], $scheduledClass), [
                'trainer_id' => $context['newTrainer']->id,
            ])
            ->assertOk();

        app(SyncTrainerSubstitutions::class)->syncAfterSubstitutionChange($context['account'], [$scheduledClass->id]);

        $scheduledClass->refresh();
        $this->assertSame($context['newTrainer']->id, $scheduledClass->trainer_id);
        $this->assertArrayNotHasKey(SyncTrainerSubstitutions::MetadataKey, $scheduledClass->metadata);
        $this->assertNotNull(data_get($scheduledClass->metadata, ScheduledClass::MANUAL_TRAINER_OVERRIDE_METADATA_KEY));
    }

    public function test_normal_form_submission_redirects_back(): void
    {
        Carbon::setTestNow('2026-07-17 09:00:00');
        $context = $this->context();
        $scheduledClass = $this->scheduledClass($context, ScheduleKind::GroupClass, '2026-07-17 10:00:00');

        $this->actingAs($context['owner'])
            ->from(route('dashboard.accounts.scheduled-classes.index', $context['account']))
            ->patch($this->updateUrl($context['account'], $scheduledClass), [
                'trainer_id' => $context['newTrainer']->id,
            ])
            ->assertRedirect(route('dashboard.accounts.scheduled-classes.index', $context['account']))
            ->assertSessionHas('status', __('app.scheduled_class_trainer_updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'default_language' => 'en',
            'enabled_schedule_kinds' => array_map(
                fn (ScheduleKind $scheduleKind): string => $scheduleKind->value,
                ScheduleKind::cases(),
            ),
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 12]);
        $activityDirection = ActivityDirection::factory()->for($account)->create();
        $groupClassType = ClassType::factory()->for($account)->for($activityDirection)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'default_capacity' => 12,
        ]);
        $privateClassType = ClassType::factory()->for($account)->for($activityDirection)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_capacity' => 1,
        ]);
        $rentalClassType = ClassType::factory()->for($account)->for($activityDirection)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_capacity' => 1,
        ]);
        $oldTrainer = Trainer::factory()->for($account)->create(['name' => 'Original Trainer']);
        $newTrainer = Trainer::factory()->for($account)->create(['name' => 'Correct Trainer']);
        $inactiveTrainer = Trainer::factory()->for($account)->create([
            'name' => 'Historical Trainer',
            'is_active' => false,
        ]);

        return compact(
            'owner',
            'account',
            'location',
            'room',
            'activityDirection',
            'groupClassType',
            'privateClassType',
            'rentalClassType',
            'oldTrainer',
            'newTrainer',
            'inactiveTrainer',
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scheduledClass(
        array $context,
        ScheduleKind $scheduleKind,
        string $startsAt,
        bool $generated = false,
        bool $hasTrainer = true,
        ?string $endsAt = null,
    ): ScheduledClass {
        $classType = match ($scheduleKind) {
            ScheduleKind::GroupClass => $context['groupClassType'],
            ScheduleKind::PrivateLesson => $context['privateClassType'],
            ScheduleKind::RoomRental => $context['rentalClassType'],
        };
        $startsAt = Carbon::parse($startsAt, 'UTC');

        return ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($context['room'])
            ->for($classType)
            ->create([
                'trainer_id' => $hasTrainer ? $context['oldTrainer']->id : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt ? Carbon::parse($endsAt, 'UTC') : $startsAt->copy()->addHour(),
                'capacity' => $scheduleKind === ScheduleKind::GroupClass ? 12 : 1,
                'is_generated' => $generated,
            ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function booking(array $context, ScheduledClass $scheduledClass): ClassBooking
    {
        $customer = Customer::factory()->for($context['account'])->create();

        return ClassBooking::factory()
            ->for($context['account'])
            ->for($scheduledClass)
            ->for($customer)
            ->create([
                'status' => ClassBookingStatus::Booked->value,
                'booked_by_user_id' => $context['owner']->id,
            ]);
    }

    private function updateUrl(Account $account, ScheduledClass $scheduledClass): string
    {
        return route('dashboard.accounts.scheduled-classes.trainer.update', [$account, $scheduledClass]);
    }
}
