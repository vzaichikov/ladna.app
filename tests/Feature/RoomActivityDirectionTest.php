<?php

namespace Tests\Feature;

use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use App\Support\RoomActivityDirectionEligibility;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RoomActivityDirectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_room_with_tenant_scoped_directions_and_duplicate_ids_are_normalized(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $poleDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Pole']);
        $exoticDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Exotic']);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.rooms.store', $account), [
                'location_id' => $location->id,
                'name' => 'Pole hall',
                'capacity' => 12,
                'is_active' => '1',
                'activity_direction_ids' => [
                    $poleDirection->id,
                    (string) $poleDirection->id,
                    $exoticDirection->id,
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $room = $account->rooms()->where('name', 'Pole hall')->firstOrFail();
        $directions = $room->activityDirections()->orderBy('activity_directions.id')->get();

        $this->assertSame(
            [$poleDirection->id, $exoticDirection->id],
            $directions->pluck('id')->all(),
        );
        $this->assertSame(
            [$account->id],
            $directions->pluck('pivot.account_id')->unique()->values()->map(fn (mixed $accountId): int => (int) $accountId)->all(),
        );

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.edit', [$account, $room]))
            ->assertOk()
            ->assertSee($poleDirection->name)
            ->assertSee($exoticDirection->name);
    }

    public function test_owner_can_replace_and_clear_room_directions(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Main hall']);
        $poleDirection = ActivityDirection::factory()->for($account)->create();
        $exoticDirection = ActivityDirection::factory()->for($account)->create();
        $room->activityDirections()->syncWithPivotValues([$poleDirection->id], ['account_id' => $account->id]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.rooms.update', [$account, $room]), [
                'location_id' => $location->id,
                'name' => 'Main hall',
                'capacity' => 10,
                'is_active' => '1',
                'activity_direction_ids' => [$exoticDirection->id],
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $this->assertSame(
            [$exoticDirection->id],
            $room->fresh()->activityDirections()->pluck('activity_directions.id')->all(),
        );

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.rooms.update', [$account, $room]), [
                'location_id' => $location->id,
                'name' => 'Main hall',
                'capacity' => 10,
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $this->assertSame([], $room->fresh()->activityDirections()->pluck('activity_directions.id')->all());

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.index', $account))
            ->assertOk()
            ->assertSee(__('app.all_activity_directions'));
    }

    public function test_edit_form_preserves_an_assigned_direction_after_it_becomes_inactive(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Main hall']);
        $direction = ActivityDirection::factory()->for($account)->create([
            'name' => 'Archived direction',
            'is_active' => false,
        ]);
        $room->activityDirections()->syncWithPivotValues([$direction->id], ['account_id' => $account->id]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.edit', [$account, $room]))
            ->assertOk()
            ->assertSee($direction->name)
            ->assertSee(__('app.inactive'));

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.rooms.update', [$account, $room]), [
                'location_id' => $location->id,
                'name' => 'Renamed hall',
                'capacity' => 10,
                'is_active' => '1',
                'activity_direction_ids' => [$direction->id],
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $this->assertSame(
            [$direction->id],
            $room->fresh()->activityDirections()->pluck('activity_directions.id')->all(),
        );
    }

    public function test_foreign_account_direction_is_rejected_without_partial_room_update(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Original hall']);
        $ownDirection = ActivityDirection::factory()->for($account)->create();
        $foreignDirection = ActivityDirection::factory()->for($otherAccount)->create();
        $room->activityDirections()->syncWithPivotValues([$ownDirection->id], ['account_id' => $account->id]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.rooms.update', [$account, $room]), [
                'location_id' => $location->id,
                'name' => 'Changed hall',
                'capacity' => 10,
                'is_active' => '1',
                'activity_direction_ids' => [$foreignDirection->id],
            ])
            ->assertSessionHasErrors('activity_direction_ids.0');

        $room->refresh();

        $this->assertSame('Original hall', $room->name);
        $this->assertSame(
            [$ownDirection->id],
            $room->activityDirections()->pluck('activity_directions.id')->all(),
        );
    }

    public function test_room_direction_eligibility_follows_schedule_kind_and_wildcard_rules(): void
    {
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        $poleDirection = ActivityDirection::factory()->for($account)->create();
        $exoticDirection = ActivityDirection::factory()->for($account)->create();
        $unrestrictedRoom = Room::factory()->for($account)->for($location)->create();
        $restrictedRoom = Room::factory()->for($account)->for($location)->create();
        $restrictedRoom->activityDirections()->syncWithPivotValues([$poleDirection->id], ['account_id' => $account->id]);

        $poleGroup = ClassType::factory()->for($account)->create([
            'activity_direction_id' => $poleDirection->id,
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $exoticGroup = ClassType::factory()->for($account)->create([
            'activity_direction_id' => $exoticDirection->id,
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $directionlessGroup = ClassType::factory()->for($account)->create([
            'activity_direction_id' => null,
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $genericPrivateLesson = ClassType::factory()->for($account)->create([
            'activity_direction_id' => null,
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
        ]);
        $polePrivateLesson = ClassType::factory()->for($account)->create([
            'activity_direction_id' => $poleDirection->id,
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
        ]);
        $roomRental = ClassType::factory()->for($account)->create([
            'activity_direction_id' => null,
            'schedule_kind' => ScheduleKind::RoomRental->value,
        ]);
        $internalClass = ClassType::factory()->for($account)->create([
            'activity_direction_id' => null,
            'schedule_kind' => ScheduleKind::InternalClass->value,
        ]);

        $eligibility = app(RoomActivityDirectionEligibility::class);

        $this->assertTrue($eligibility->roomCanHost($account, $unrestrictedRoom, $directionlessGroup));
        $this->assertTrue($eligibility->roomCanHost($account, $restrictedRoom, $poleGroup));
        $this->assertFalse($eligibility->roomCanHost($account, $restrictedRoom, $exoticGroup));
        $this->assertFalse($eligibility->roomCanHost($account, $restrictedRoom, $directionlessGroup));
        $this->assertTrue($eligibility->roomCanHost($account, $restrictedRoom, $genericPrivateLesson, $poleDirection->id));
        $this->assertFalse($eligibility->roomCanHost($account, $restrictedRoom, $genericPrivateLesson, $exoticDirection->id));
        $this->assertFalse($eligibility->roomCanHost($account, $restrictedRoom, $genericPrivateLesson));
        $this->assertTrue($eligibility->roomCanHost($account, $restrictedRoom, $polePrivateLesson, $exoticDirection->id));
        $this->assertTrue($eligibility->roomCanHost($account, $restrictedRoom, $roomRental));
        $this->assertTrue($eligibility->roomCanHost($account, $restrictedRoom, $internalClass));
    }

    public function test_room_query_and_collection_filters_apply_the_same_wildcard_rules(): void
    {
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        $poleDirection = ActivityDirection::factory()->for($account)->create();
        $exoticDirection = ActivityDirection::factory()->for($account)->create();
        $unrestrictedRoom = Room::factory()->for($account)->for($location)->create();
        $poleRoom = Room::factory()->for($account)->for($location)->create();
        $exoticRoom = Room::factory()->for($account)->for($location)->create();
        $poleRoom->activityDirections()->syncWithPivotValues([$poleDirection->id], ['account_id' => $account->id]);
        $exoticRoom->activityDirections()->syncWithPivotValues([$exoticDirection->id], ['account_id' => $account->id]);
        $poleGroup = ClassType::factory()->for($account)->create([
            'activity_direction_id' => $poleDirection->id,
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $directionlessGroup = ClassType::factory()->for($account)->create([
            'activity_direction_id' => null,
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $roomRental = ClassType::factory()->for($account)->create([
            'activity_direction_id' => null,
            'schedule_kind' => ScheduleKind::RoomRental->value,
        ]);
        $eligibility = app(RoomActivityDirectionEligibility::class);

        $queryRoomIds = $eligibility
            ->scopeRoomQuery($account->rooms(), $account, $poleGroup)
            ->orderBy('rooms.id')
            ->pluck('rooms.id')
            ->all();
        $collectionRoomIds = $eligibility
            ->filterRooms($account->rooms()->with('activityDirections')->get(), $account, $poleGroup)
            ->sortBy('id')
            ->pluck('id')
            ->values()
            ->all();
        $directionlessRoomIds = $eligibility
            ->scopeRoomQuery($account->rooms(), $account, $directionlessGroup)
            ->pluck('rooms.id')
            ->all();
        $rentalRoomIds = $eligibility
            ->scopeRoomQuery($account->rooms(), $account, $roomRental)
            ->orderBy('rooms.id')
            ->pluck('rooms.id')
            ->all();

        $this->assertSame([$unrestrictedRoom->id, $poleRoom->id], $queryRoomIds);
        $this->assertSame($queryRoomIds, $collectionRoomIds);
        $this->assertSame([$unrestrictedRoom->id], $directionlessRoomIds);
        $this->assertSame([$unrestrictedRoom->id, $poleRoom->id, $exoticRoom->id], $rentalRoomIds);
    }

    public function test_room_direction_pivot_is_removed_when_either_side_is_deleted(): void
    {
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        $direction = ActivityDirection::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $room->activityDirections()->syncWithPivotValues([$direction->id], ['account_id' => $account->id]);

        $room->delete();

        $this->assertFalse($direction->rooms()->whereKey($room->id)->exists());

        $secondRoom = Room::factory()->for($account)->for($location)->create();
        $secondRoom->activityDirections()->syncWithPivotValues([$direction->id], ['account_id' => $account->id]);

        $direction->delete();

        $this->assertFalse($secondRoom->activityDirections()->exists());
    }
}
