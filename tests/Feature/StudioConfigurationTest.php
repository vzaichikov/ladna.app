<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StudioConfigurationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_room_slug_is_unique_within_location_not_globally(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $locationOne = Location::factory()->for($account)->create();
        $locationTwo = Location::factory()->for($account)->create();
        Room::factory()->for($account)->for($locationOne)->create(['slug' => 'big-hall']);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.rooms.store', $account), [
                'location_id' => $locationTwo->id,
                'name' => 'Big Hall',
                'slug' => 'big-hall',
                'capacity' => 12,
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $this->assertTrue(Room::whereBelongsTo($locationTwo)->where('slug', 'big-hall')->exists());
    }

    public function test_owner_cannot_manage_room_outside_owned_account(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $otherAccount->addOwner($otherOwner);
        $otherLocation = Location::factory()->for($otherAccount)->create();
        $otherRoom = Room::factory()->for($otherAccount)->for($otherLocation)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.edit', [$account, $otherRoom]))
            ->assertNotFound();
    }

    public function test_session_locale_changes_internal_ui_language(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('locale.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->actingAs($owner)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_owner_can_delete_owned_room(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.rooms.destroy', [$account, $room]))
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $this->assertModelMissing($room);
    }

    public function test_deleting_class_type_removes_its_scheduled_classes(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create();

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.class-types.destroy', [$account, $classType]))
            ->assertRedirect(route('dashboard.accounts.class-types.index', $account));

        $this->assertModelMissing($classType);
        $this->assertModelMissing($scheduledClass);
    }

    public function test_cyrillic_slugs_are_normalized_and_suffixed_within_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.activity-directions.store', $account), [
                'name' => 'Силова група',
                'slug' => 'Танці для початківців',
                'color' => '#A78AB9',
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.activity-directions.index', $account));

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.activity-directions.store', $account), [
                'name' => 'Силова група друга',
                'slug' => 'Танці для початківців',
                'color' => '#A78AB9',
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.activity-directions.index', $account));

        $this->assertTrue(ActivityDirection::whereBelongsTo($account)->where('slug', 'tantsi-dlya-pochatkivtsiv')->exists());
        $this->assertTrue(ActivityDirection::whereBelongsTo($account)->where('slug', 'tantsi-dlya-pochatkivtsiv-2')->exists());
    }
}
