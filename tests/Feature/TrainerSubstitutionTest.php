<?php

namespace Tests\Feature;

use App\Actions\SyncTrainerSubstitutions;
use App\Enums\AccountRole;
use App\Enums\ScheduleKind;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduleSeries;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrainerSubstitutionTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_replace_one_past_class_within_two_days_and_delete_restores_trainer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 10:00:00', 'UTC'));
        [$owner, $account, $location, $room, $classType, $trainer, $substitute] = $this->context();
        $scheduledClass = $this->scheduledClass($account, $location, $room, $classType, $trainer, '2026-06-26 12:00:00');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.trainers.substitutions.store', [$account, $trainer]), [
                'mode' => 'classes',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_date' => '2026-06-26',
                'scheduled_class_ids' => [$scheduledClass->id],
                'substitute_trainer_id' => $substitute->id,
            ])
            ->assertRedirect(route('dashboard.accounts.trainers.edit', [$account, $trainer]));

        $scheduledClass->refresh();
        $substitution = $account->trainerSubstitutions()->firstOrFail();

        $this->assertSame($substitute->id, $scheduledClass->trainer_id);
        $this->assertSame($trainer->id, $scheduledClass->metadata[SyncTrainerSubstitutions::MetadataKey]['original_trainer_id']);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.trainers.substitutions.destroy', [$account, $trainer, $substitution]))
            ->assertRedirect(route('dashboard.accounts.trainers.edit', [$account, $trainer]));

        $this->assertSame($trainer->id, $scheduledClass->refresh()->trainer_id);
        $this->assertArrayNotHasKey(SyncTrainerSubstitutions::MetadataKey, $scheduledClass->metadata ?? []);
    }

    public function test_one_time_substitution_rejects_past_class_older_than_two_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 10:00:00', 'UTC'));
        [$owner, $account, $location, $room, $classType, $trainer, $substitute] = $this->context();
        $scheduledClass = $this->scheduledClass($account, $location, $room, $classType, $trainer, '2026-06-25 12:00:00');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.trainers.substitutions.store', [$account, $trainer]), [
                'mode' => 'classes',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_date' => '2026-06-25',
                'scheduled_class_ids' => [$scheduledClass->id],
                'substitute_trainer_id' => $substitute->id,
            ])
            ->assertSessionHasErrors('class_date');

        $this->assertSame($trainer->id, $scheduledClass->refresh()->trainer_id);
    }

    public function test_period_substitution_includes_today_and_generated_classes_keep_capacity_sync(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        [$owner, $account, $location, $room, $classType, $trainer, $substitute] = $this->context();
        $classType->forceFill([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'default_capacity' => 8,
            'default_duration_minutes' => 60,
        ])->save();
        ScheduleSeries::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'weekday' => now('UTC')->isoWeekday(),
                'start_time' => '14:00',
                'start_date' => '2026-06-28',
            ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.trainers.substitutions.store', [$account, $trainer]), [
                'mode' => 'period',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'date_from' => '2026-06-28',
                'date_to' => '2026-06-28',
                'class_type_ids' => [$classType->id],
                'substitute_trainer_id' => $substitute->id,
            ])
            ->assertRedirect(route('dashboard.accounts.trainers.edit', [$account, $trainer]));

        $todayClass = $account->scheduledClasses()->orderBy('starts_at')->firstOrFail();

        $this->assertSame($substitute->id, $todayClass->trainer_id);
        $this->assertSame(8, $todayClass->capacity);

        $classType->forceFill(['default_capacity' => 5])->save();
        $this->artisan('schedule:generate', ['--account' => $account->id])->assertSuccessful();

        $todayClass->refresh();

        $this->assertSame($substitute->id, $todayClass->trainer_id);
        $this->assertSame(5, $todayClass->capacity);
    }

    public function test_schedule_generate_account_option_limits_work_to_one_account_and_applies_substitutions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        [$owner, $account, $location, $room, $classType, $trainer, $substitute] = $this->context();
        [$otherOwner, $otherAccount, $otherLocation, $otherRoom, $otherClassType, $otherTrainer] = $this->context();
        unset($owner, $otherOwner);

        ScheduleSeries::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'weekday' => now('UTC')->isoWeekday(),
                'start_time' => '14:00',
                'start_date' => '2026-06-28',
            ]);
        ScheduleSeries::factory()
            ->for($otherAccount)
            ->for($otherLocation)
            ->for($otherRoom)
            ->for($otherClassType)
            ->for($otherTrainer)
            ->create([
                'weekday' => now('UTC')->isoWeekday(),
                'start_time' => '14:00',
                'start_date' => '2026-06-28',
            ]);
        $account->trainerSubstitutions()->create([
            'replaced_trainer_id' => $trainer->id,
            'substitute_trainer_id' => $substitute->id,
            'location_id' => $location->id,
            'room_id' => $room->id,
            'mode' => 'period',
            'date_from' => '2026-06-28',
            'date_to' => '2026-06-28',
            'class_type_ids' => [$classType->id],
            'replaced_trainer_name' => $trainer->name,
            'substitute_trainer_name' => $substitute->name,
            'location_name' => $location->name,
            'room_name' => $room->name,
        ]);

        $this->artisan('schedule:generate', ['--account' => $account->id])->assertSuccessful();

        $this->assertSame($substitute->id, $account->scheduledClasses()->firstOrFail()->trainer_id);
        $this->assertSame(0, $otherAccount->scheduledClasses()->count());
    }

    public function test_started_period_substitution_can_be_edited_without_moving_start_further_into_past(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00', 'UTC'));
        [$owner, $account, $location, $room, $classType, $trainer, $substitute] = $this->context();
        $substitution = $account->trainerSubstitutions()->create([
            'replaced_trainer_id' => $trainer->id,
            'substitute_trainer_id' => $substitute->id,
            'location_id' => $location->id,
            'room_id' => $room->id,
            'mode' => 'period',
            'date_from' => '2026-06-28',
            'date_to' => '2026-06-30',
            'class_type_ids' => [$classType->id],
            'replaced_trainer_name' => $trainer->name,
            'substitute_trainer_name' => $substitute->name,
            'location_name' => $location->name,
            'room_name' => $room->name,
        ]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.trainers.substitutions.update', [$account, $trainer, $substitution]), [
                'mode' => 'period',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'date_from' => '2026-06-28',
                'date_to' => '2026-07-01',
                'class_type_ids' => [$classType->id],
                'substitute_trainer_id' => $substitute->id,
            ])
            ->assertRedirect(route('dashboard.accounts.trainers.edit', [$account, $trainer]));

        $this->assertSame('2026-07-01', $substitution->refresh()->date_to->toDateString());

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.trainers.substitutions.update', [$account, $trainer, $substitution]), [
                'mode' => 'period',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'date_from' => '2026-06-27',
                'date_to' => '2026-07-01',
                'class_type_ids' => [$classType->id],
                'substitute_trainer_id' => $substitute->id,
            ])
            ->assertSessionHasErrors('date_from');
    }

    public function test_substitute_trainer_time_conflict_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        [$owner, $account, $location, $room, $classType, $trainer, $substitute] = $this->context();
        $scheduledClass = $this->scheduledClass($account, $location, $room, $classType, $trainer, '2026-06-28 14:00:00');
        $this->scheduledClass($account, $location, $room, $classType, $substitute, '2026-06-28 14:30:00');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.trainers.substitutions.store', [$account, $trainer]), [
                'mode' => 'classes',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_date' => '2026-06-28',
                'scheduled_class_ids' => [$scheduledClass->id],
                'substitute_trainer_id' => $substitute->id,
            ])
            ->assertSessionHasErrors('substitute_trainer_id');

        $this->assertSame($trainer->id, $scheduledClass->refresh()->trainer_id);
    }

    public function test_trainer_with_manage_trainers_permission_can_create_substitution(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));
        [$owner, $account, $location, $room, $classType, $trainer, $substitute] = $this->context();
        unset($owner);
        $staffUser = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($staffUser, 'user')
            ->create([
                'role' => AccountRole::Trainer->value,
                'permissions' => [StudioPermission::ManageTrainers->value],
            ]);
        $scheduledClass = $this->scheduledClass($account, $location, $room, $classType, $trainer, '2026-06-28 14:00:00');

        $this->actingAs($staffUser)
            ->post(route('dashboard.accounts.trainers.substitutions.store', [$account, $trainer]), [
                'mode' => 'classes',
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_date' => '2026-06-28',
                'scheduled_class_ids' => [$scheduledClass->id],
                'substitute_trainer_id' => $substitute->id,
            ])
            ->assertRedirect(route('dashboard.accounts.trainers.edit', [$account, $trainer]));

        $this->assertSame($substitute->id, $scheduledClass->refresh()->trainer_id);
    }

    /**
     * @return array{0: User, 1: Account, 2: Location, 3: Room, 4: ClassType, 5: Trainer, 6: Trainer}
     */
    private function context(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 10]);
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'default_duration_minutes' => 60,
            'default_capacity' => 10,
        ]);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Main Trainer']);
        $substitute = Trainer::factory()->for($account)->create(['name' => 'Substitute Trainer']);

        return [$owner, $account, $location, $room, $classType, $trainer, $substitute];
    }

    private function scheduledClass(
        Account $account,
        Location $location,
        Room $room,
        ClassType $classType,
        Trainer $trainer,
        string $startsAt,
    ): ScheduledClass {
        $startsAt = Carbon::parse($startsAt, 'UTC');

        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
                'capacity' => 10,
            ]);
    }
}
