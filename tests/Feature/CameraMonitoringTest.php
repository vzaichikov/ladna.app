<?php

namespace Tests\Feature;

use App\Enums\CustomerOtpSenderScope;
use App\Models\Account;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use App\Support\MediaMtxCameraGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CameraMonitoringTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_toggle_camera_features_near_otp(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($platformAdmin)
            ->put(route('platform.accounts.customer-auth.update', $account), [
                'allow_otp' => '1',
                'allow_rtsp_cameras' => '1',
                'enable_people_counter' => '1',
                'otp_sender_scope' => CustomerOtpSenderScope::Platform->value,
                'otp_provider' => null,
            ])
            ->assertRedirect(route('platform.accounts.customer-auth.edit', $account));

        $account->refresh();

        $this->assertTrue($account->allow_rtsp_cameras);
        $this->assertTrue($account->enable_people_counter);

        $this->actingAs($platformAdmin)
            ->get(route('platform.accounts.customer-auth.edit', $account))
            ->assertOk()
            ->assertSee(__('app.enable_customer_otp_tariff'), false)
            ->assertSee(__('app.enable_rtsp_camera_support'), false)
            ->assertSee(__('app.enable_people_counter'), false);

        $this->actingAs($owner)
            ->put(route('platform.accounts.customer-auth.update', $account), [
                'allow_rtsp_cameras' => '0',
                'enable_people_counter' => '0',
                'otp_sender_scope' => CustomerOtpSenderScope::Platform->value,
            ])
            ->assertForbidden();
    }

    public function test_room_rtsp_fields_are_available_only_when_account_allows_rtsp(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['allow_rtsp_cameras' => false]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.create', $account))
            ->assertOk()
            ->assertDontSee('name="rtsp_url"', false)
            ->assertDontSee('name="rtsp_enabled"', false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.rooms.store', $account), [
                'location_id' => $location->id,
                'name' => 'Big Hall',
                'slug' => 'big-hall',
                'capacity' => 12,
                'is_active' => '1',
                'rtsp_url' => 'rtsp://camera.example.test/live',
                'rtsp_enabled' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $room = Room::whereBelongsTo($account)->where('slug', 'big-hall')->firstOrFail();

        $this->assertNull($room->rtsp_url);
        $this->assertFalse($room->rtsp_enabled);

        $account->update(['allow_rtsp_cameras' => true]);
        $cameraUrl = 'rtsp://user:secret@camera.example.test:554/live';

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.edit', [$account, $room]))
            ->assertOk()
            ->assertSee('name="rtsp_url"', false)
            ->assertSee('name="rtsp_enabled"', false)
            ->assertSee(route('dashboard.accounts.rooms.test-camera', $account), false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.rooms.update', [$account, $room]), [
                'location_id' => $location->id,
                'name' => 'Big Hall',
                'slug' => 'big-hall',
                'capacity' => 12,
                'is_active' => '1',
                'rtsp_url' => $cameraUrl,
                'rtsp_enabled' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $room->refresh();

        $this->assertSame($cameraUrl, $room->rtsp_url);
        $this->assertTrue($room->rtsp_enabled);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.rooms.update', [$account, $room]), [
                'location_id' => $location->id,
                'name' => 'Big Hall',
                'slug' => 'big-hall',
                'capacity' => 12,
                'is_active' => '1',
                'rtsp_url' => '',
                'rtsp_enabled' => '1',
            ])
            ->assertSessionHasErrors('rtsp_url');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.rooms.test-camera', $account), [
                'rtsp_url' => 'https://camera.example.test/live',
            ])
            ->assertSessionHasErrors('rtsp_url');
    }

    public function test_camera_page_is_standalone_and_gated_by_rtsp_allowance(): void
    {
        config([
            'services.mediamtx.api_url' => 'http://mediamtx.test',
            'services.mediamtx.public_url' => 'https://cam.example.test',
            'services.mediamtx.webrtc_prefix' => '/webrtc',
            'services.mediamtx.source_on_demand' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'http://mediamtx.test/v3/config/paths/get/*' => Http::response(['status' => 'error'], 404),
            'http://mediamtx.test/v3/config/paths/add/*' => Http::response(['status' => 'ok']),
        ]);

        $owner = User::factory()->create();
        $account = Account::factory()->create(['allow_rtsp_cameras' => false]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Main Studio']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.index', $account))
            ->assertOk()
            ->assertDontSee(__('app.cameras'), false)
            ->assertDontSee(route('dashboard.accounts.cameras.index', $account), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.cameras.index', $account))
            ->assertNotFound();

        $account->update(['allow_rtsp_cameras' => true]);

        $this->assertStringNotContainsString('/reports/', route('dashboard.accounts.cameras.index', $account));

        $enabledRoom = Room::factory()
            ->for($account)
            ->for($location)
            ->create([
                'name' => 'Camera Hall',
                'rtsp_url' => 'rtsp://user:secret@camera.example.test/live',
                'rtsp_enabled' => true,
            ]);
        $disabledRoom = Room::factory()
            ->for($account)
            ->for($location)
            ->create([
                'name' => 'Disabled Hall',
                'rtsp_url' => 'rtsp://camera.example.test/disabled',
                'rtsp_enabled' => false,
            ]);
        $pathName = app(MediaMtxCameraGateway::class)->pathName($enabledRoom);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee(__('app.cameras'), false)
            ->assertSee(route('dashboard.accounts.cameras.index', $account), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.index', $account))
            ->assertOk()
            ->assertSee(__('app.trainer_report_title'), false)
            ->assertDontSee(__('app.cameras_copy'), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.cameras.index', $account))
            ->assertOk()
            ->assertSee($enabledRoom->name)
            ->assertSee($location->name)
            ->assertDontSee($disabledRoom->name)
            ->assertSee('https://cam.example.test/webrtc/'.$pathName.'/', false)
            ->assertDontSee('rtsp://', false);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://mediamtx.test/v3/config/paths/add/'.$pathName
            && $request['source'] === 'rtsp://user:secret@camera.example.test/live'
            && $request['sourceOnDemand'] === true
            && $request['rtspTransport'] === 'tcp');
        Http::assertSentCount(2);
    }

    public function test_camera_gateway_patches_existing_mediamtx_path(): void
    {
        config([
            'services.mediamtx.api_url' => 'http://mediamtx.test',
            'services.mediamtx.public_url' => 'https://cam.example.test',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'http://mediamtx.test/v3/config/paths/get/*' => Http::response(['source' => 'rtsp://old-camera/live']),
            'http://mediamtx.test/v3/config/paths/patch/*' => Http::response(['status' => 'ok']),
        ]);

        $account = Account::factory()->create(['allow_rtsp_cameras' => true]);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()
            ->for($account)
            ->for($location)
            ->create([
                'rtsp_url' => 'rtsp://new-camera.example.test/live',
                'rtsp_enabled' => true,
            ]);
        $gateway = app(MediaMtxCameraGateway::class);
        $pathName = $gateway->pathName($room);

        $gateway->ensurePath($room);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'http://mediamtx.test/v3/config/paths/patch/'.$pathName
            && $request['source'] === 'rtsp://new-camera.example.test/live'
            && $request['sourceOnDemand'] === true);
        Http::assertSentCount(2);
    }
}
