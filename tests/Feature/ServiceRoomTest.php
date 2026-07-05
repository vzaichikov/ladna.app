<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Location;
use App\Models\ServiceRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ServiceRoomTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_manage_service_rooms_when_rtsp_is_enabled(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['allow_rtsp_cameras' => false]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Main Studio']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.service-rooms.index', $account))
            ->assertNotFound();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.service-rooms.store', $account), [
                'location_id' => $location->id,
                'name' => 'Reception',
                'slug' => 'reception',
                'is_active' => '1',
                'rtsp_url' => 'rtsp://camera.example.test/reception',
                'rtsp_enabled' => '1',
            ])
            ->assertForbidden();

        $account->update(['allow_rtsp_cameras' => true]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.index', $account))
            ->assertOk()
            ->assertSee(route('dashboard.accounts.service-rooms.index', $account), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.service-rooms.create', $account))
            ->assertOk()
            ->assertSee(route('dashboard.accounts.service-rooms.test-camera', $account), false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.service-rooms.store', $account), [
                'location_id' => $location->id,
                'name' => 'Reception',
                'slug' => 'reception',
                'description' => 'Front desk camera',
                'color' => '#38BDF8',
                'is_active' => '1',
                'rtsp_url' => 'https://camera.example.test/reception',
                'rtsp_enabled' => '1',
            ])
            ->assertSessionHasErrors('rtsp_url');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.service-rooms.store', $account), [
                'location_id' => $location->id,
                'name' => 'Reception',
                'slug' => 'reception',
                'description' => 'Front desk camera',
                'color' => '#38BDF8',
                'is_active' => '1',
                'rtsp_url' => 'rtsp://camera.example.test/reception',
                'rtsp_enabled' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.service-rooms.index', $account));

        $serviceRoom = ServiceRoom::whereBelongsTo($account)->where('slug', 'reception')->firstOrFail();

        $this->assertSame('rtsp://camera.example.test/reception', $serviceRoom->rtsp_url);
        $this->assertTrue($serviceRoom->rtsp_enabled);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.service-rooms.edit', [$account, $serviceRoom]))
            ->assertOk()
            ->assertSee('Reception');

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.service-rooms.update', [$account, $serviceRoom]), [
                'location_id' => $location->id,
                'name' => 'Reception desk',
                'slug' => 'reception-desk',
                'description' => '',
                'color' => '',
                'is_active' => '1',
                'rtsp_url' => '',
                'rtsp_enabled' => '0',
            ])
            ->assertRedirect(route('dashboard.accounts.service-rooms.index', $account));

        $serviceRoom->refresh();

        $this->assertSame('Reception desk', $serviceRoom->name);
        $this->assertSame('reception-desk', $serviceRoom->slug);
        $this->assertNull($serviceRoom->rtsp_url);
        $this->assertFalse($serviceRoom->rtsp_enabled);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.service-rooms.destroy', [$account, $serviceRoom]))
            ->assertRedirect(route('dashboard.accounts.service-rooms.index', $account));

        $this->assertModelMissing($serviceRoom);
    }

    public function test_owner_cannot_manage_service_room_outside_owned_account(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $account = Account::factory()->create(['allow_rtsp_cameras' => true]);
        $otherAccount = Account::factory()->create(['allow_rtsp_cameras' => true]);
        $account->addOwner($owner);
        $otherAccount->addOwner($otherOwner);
        $otherLocation = Location::factory()->for($otherAccount)->create();
        $otherServiceRoom = ServiceRoom::factory()->for($otherAccount)->for($otherLocation)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.service-rooms.edit', [$account, $otherServiceRoom]))
            ->assertNotFound();
    }

    public function test_service_rooms_do_not_appear_in_business_room_filters(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $serviceRoom = ServiceRoom::factory()->for($account)->for($location)->create([
            'name' => 'Reception Only',
            'rtsp_url' => 'rtsp://camera.example.test/reception',
            'rtsp_enabled' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertDontSee($serviceRoom->name);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.create', $account))
            ->assertOk()
            ->assertDontSee($serviceRoom->name);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.people-counter', $account))
            ->assertOk()
            ->assertDontSee($serviceRoom->name);

        $this->assertFalse($account->rooms()->whereKey($serviceRoom->id)->exists());
    }
}
