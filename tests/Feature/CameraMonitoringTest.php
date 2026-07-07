<?php

namespace Tests\Feature;

use App\Enums\CustomerOtpSenderScope;
use App\Models\Account;
use App\Models\Location;
use App\Models\Room;
use App\Models\ServiceRoom;
use App\Models\User;
use App\Support\MediaMtxCameraGateway;
use App\Support\RtspCameraService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CameraMonitoringTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_mediamtx_public_url_is_derived_from_app_url(): void
    {
        $previousAppUrl = $_ENV['APP_URL'] ?? null;
        $previousServerAppUrl = $_SERVER['APP_URL'] ?? null;
        $previousMediaMtxPublicUrl = $_ENV['MEDIAMTX_PUBLIC_URL'] ?? null;
        $previousServerMediaMtxPublicUrl = $_SERVER['MEDIAMTX_PUBLIC_URL'] ?? null;

        try {
            $_ENV['APP_URL'] = 'https://studio.example.test';
            $_SERVER['APP_URL'] = 'https://studio.example.test';
            unset($_ENV['MEDIAMTX_PUBLIC_URL'], $_SERVER['MEDIAMTX_PUBLIC_URL']);

            $services = require base_path('config/services.php');

            $this->assertSame('https://cam.studio.example.test', $services['mediamtx']['public_url']);
        } finally {
            if ($previousAppUrl === null) {
                unset($_ENV['APP_URL']);
            } else {
                $_ENV['APP_URL'] = $previousAppUrl;
            }

            if ($previousServerAppUrl === null) {
                unset($_SERVER['APP_URL']);
            } else {
                $_SERVER['APP_URL'] = $previousServerAppUrl;
            }

            if ($previousMediaMtxPublicUrl === null) {
                unset($_ENV['MEDIAMTX_PUBLIC_URL']);
            } else {
                $_ENV['MEDIAMTX_PUBLIC_URL'] = $previousMediaMtxPublicUrl;
            }

            if ($previousServerMediaMtxPublicUrl === null) {
                unset($_SERVER['MEDIAMTX_PUBLIC_URL']);
            } else {
                $_SERVER['MEDIAMTX_PUBLIC_URL'] = $previousServerMediaMtxPublicUrl;
            }
        }
    }

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
                'enable_telegram_alerts' => '0',
                'otp_sender_scope' => CustomerOtpSenderScope::Platform->value,
                'otp_provider' => null,
            ])
            ->assertRedirect(route('platform.accounts.customer-auth.edit', $account));

        $account->refresh();

        $this->assertTrue($account->allow_rtsp_cameras);
        $this->assertTrue($account->enable_people_counter);
        $this->assertFalse($account->enable_telegram_alerts);

        $this->actingAs($platformAdmin)
            ->get(route('platform.accounts.customer-auth.edit', $account))
            ->assertOk()
            ->assertSee(__('app.enable_customer_otp_tariff'), false)
            ->assertSee(__('app.enable_rtsp_camera_support'), false)
            ->assertSee(__('app.enable_people_counter'), false)
            ->assertSee(__('app.enable_telegram_alerts'), false);

        $this->actingAs($owner)
            ->put(route('platform.accounts.customer-auth.update', $account), [
                'allow_rtsp_cameras' => '0',
                'enable_people_counter' => '0',
                'enable_telegram_alerts' => '1',
                'otp_sender_scope' => CustomerOtpSenderScope::Platform->value,
            ])
            ->assertForbidden();
    }

    public function test_room_rtsp_fields_are_platform_admin_only(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create();
        $account = Account::factory()->create(['allow_rtsp_cameras' => false]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.create', $account))
            ->assertOk()
            ->assertDontSee('name="rtsp_url"', false)
            ->assertDontSee('name="rtsp_enabled"', false)
            ->assertDontSee('name="people_counter_capture_delay_seconds"', false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.rooms.store', $account), [
                'location_id' => $location->id,
                'name' => 'Big Hall',
                'slug' => 'big-hall',
                'capacity' => 12,
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $room = Room::whereBelongsTo($account)->where('slug', 'big-hall')->firstOrFail();

        $this->assertNull($room->rtsp_url);
        $this->assertFalse($room->rtsp_enabled);
        $this->assertNull($room->people_counter_capture_delay_seconds);

        $account->update(['allow_rtsp_cameras' => true]);
        $cameraUrl = 'rtsp://user:secret@camera.example.test:554/live';

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.create', $account))
            ->assertOk()
            ->assertDontSee('name="rtsp_url"', false)
            ->assertDontSee('name="rtsp_enabled"', false)
            ->assertDontSee('name="people_counter_capture_delay_seconds"', false)
            ->assertDontSee(route('dashboard.accounts.rooms.test-camera', $account), false)
            ->assertSee(__('app.rtsp_camera_add_support_notice'), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.edit', [$account, $room]))
            ->assertOk()
            ->assertDontSee('name="rtsp_url"', false)
            ->assertDontSee('name="rtsp_enabled"', false)
            ->assertDontSee('name="people_counter_capture_delay_seconds"', false)
            ->assertDontSee(route('dashboard.accounts.rooms.test-camera', $account), false)
            ->assertSee(__('app.rtsp_camera_add_support_notice'), false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.rooms.update', [$account, $room]), [
                'location_id' => $location->id,
                'name' => 'Big Hall',
                'slug' => 'big-hall',
                'capacity' => 12,
                'is_active' => '1',
                'rtsp_url' => $cameraUrl,
                'rtsp_enabled' => '1',
                'people_counter_capture_delay_seconds' => '4',
            ])
            ->assertSessionHasErrors(['rtsp_url', 'rtsp_enabled', 'people_counter_capture_delay_seconds']);

        $room->refresh();

        $this->assertNull($room->rtsp_url);
        $this->assertFalse($room->rtsp_enabled);
        $this->assertNull($room->people_counter_capture_delay_seconds);

        $this->actingAs($platformAdmin)
            ->get(route('dashboard.accounts.rooms.create', $account))
            ->assertOk()
            ->assertSee('name="rtsp_url"', false)
            ->assertSee('name="rtsp_enabled"', false)
            ->assertSee('name="people_counter_capture_delay_seconds"', false)
            ->assertSee(route('dashboard.accounts.rooms.test-camera', $account), false);

        $this->actingAs($platformAdmin)
            ->post(route('dashboard.accounts.rooms.store', $account), [
                'location_id' => $location->id,
                'name' => 'Platform Camera Hall',
                'slug' => 'platform-camera-hall',
                'capacity' => 8,
                'is_active' => '1',
                'rtsp_url' => $cameraUrl,
                'rtsp_enabled' => '1',
                'people_counter_capture_delay_seconds' => '4',
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $cameraRoom = Room::whereBelongsTo($account)->where('slug', 'platform-camera-hall')->firstOrFail();

        $this->assertSame($cameraUrl, $cameraRoom->rtsp_url);
        $this->assertTrue($cameraRoom->rtsp_enabled);
        $this->assertSame(4, $cameraRoom->people_counter_capture_delay_seconds);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.edit', [$account, $cameraRoom]))
            ->assertOk()
            ->assertDontSee('name="rtsp_url"', false)
            ->assertDontSee('name="rtsp_enabled"', false)
            ->assertDontSee('name="people_counter_capture_delay_seconds"', false)
            ->assertDontSee(route('dashboard.accounts.rooms.test-camera', $account), false)
            ->assertSee($cameraUrl)
            ->assertSee(__('app.rtsp_camera_change_support_notice'), false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.rooms.update', [$account, $cameraRoom]), [
                'location_id' => $location->id,
                'name' => 'Platform Camera Hall updated',
                'slug' => 'platform-camera-hall-updated',
                'capacity' => 10,
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $cameraRoom->refresh();

        $this->assertSame('Platform Camera Hall updated', $cameraRoom->name);
        $this->assertSame($cameraUrl, $cameraRoom->rtsp_url);
        $this->assertTrue($cameraRoom->rtsp_enabled);
        $this->assertSame(4, $cameraRoom->people_counter_capture_delay_seconds);

        $updatedCameraUrl = 'rtsp://camera.example.test/updated';

        $this->actingAs($platformAdmin)
            ->put(route('dashboard.accounts.rooms.update', [$account, $cameraRoom]), [
                'location_id' => $location->id,
                'name' => 'Platform Camera Hall updated',
                'slug' => 'platform-camera-hall-updated',
                'capacity' => 10,
                'is_active' => '1',
                'rtsp_url' => $updatedCameraUrl,
                'rtsp_enabled' => '1',
                'people_counter_capture_delay_seconds' => '',
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $cameraRoom->refresh();

        $this->assertSame($updatedCameraUrl, $cameraRoom->rtsp_url);
        $this->assertTrue($cameraRoom->rtsp_enabled);
        $this->assertNull($cameraRoom->people_counter_capture_delay_seconds);

        $this->actingAs($platformAdmin)
            ->put(route('dashboard.accounts.rooms.update', [$account, $cameraRoom]), [
                'location_id' => $location->id,
                'name' => 'Platform Camera Hall updated',
                'slug' => 'platform-camera-hall-updated',
                'capacity' => 10,
                'is_active' => '1',
                'rtsp_url' => $updatedCameraUrl,
                'rtsp_enabled' => '1',
                'people_counter_capture_delay_seconds' => '31',
            ])
            ->assertSessionHasErrors('people_counter_capture_delay_seconds');

        $this->actingAs($platformAdmin)
            ->put(route('dashboard.accounts.rooms.update', [$account, $cameraRoom]), [
                'location_id' => $location->id,
                'name' => 'Platform Camera Hall updated',
                'slug' => 'platform-camera-hall-updated',
                'capacity' => 10,
                'is_active' => '1',
                'rtsp_url' => '',
                'rtsp_enabled' => '1',
            ])
            ->assertSessionHasErrors('rtsp_url');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.rooms.test-camera', $account), [
                'rtsp_url' => $cameraUrl,
            ])
            ->assertForbidden();

        $this->actingAs($platformAdmin)
            ->post(route('dashboard.accounts.rooms.test-camera', $account), [
                'rtsp_url' => 'https://camera.example.test/live',
            ])
            ->assertSessionHasErrors('rtsp_url');

        $fakeCameraService = new class extends RtspCameraService
        {
            public ?string $url = null;

            /**
             * @return array{ok: bool, message: string}
             */
            public function test(string $url, int $timeoutSeconds = self::TEST_TIMEOUT_SECONDS): array
            {
                $this->url = $url;

                return [
                    'ok' => true,
                    'message' => 'ok',
                ];
            }
        };

        $this->app->instance(RtspCameraService::class, $fakeCameraService);

        $this->actingAs($platformAdmin)
            ->post(route('dashboard.accounts.rooms.test-camera', $account), [
                'rtsp_url' => $updatedCameraUrl,
            ])
            ->assertRedirect()
            ->assertSessionHas('rtsp_camera_test', [
                'ok' => true,
                'message' => 'ok',
            ]);

        $this->assertSame($updatedCameraUrl, $fakeCameraService->url);
    }

    public function test_platform_account_page_links_to_room_management(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create(['allow_rtsp_cameras' => true]);

        $this->actingAs($platformAdmin)
            ->get(route('platform.accounts.show', $account))
            ->assertOk()
            ->assertSee(route('dashboard.accounts.rooms.index', $account), false)
            ->assertSee(route('dashboard.accounts.service-rooms.index', $account), false);
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
        $enabledServiceRoom = ServiceRoom::factory()
            ->for($account)
            ->for($location)
            ->create([
                'name' => 'Reception Camera',
                'rtsp_url' => 'rtsp://user:secret@camera.example.test/reception',
                'rtsp_enabled' => true,
            ]);
        $disabledServiceRoom = ServiceRoom::factory()
            ->for($account)
            ->for($location)
            ->create([
                'name' => 'Closed Corridor',
                'rtsp_url' => 'rtsp://camera.example.test/corridor',
                'rtsp_enabled' => false,
            ]);
        $gateway = app(MediaMtxCameraGateway::class);
        $pathName = $gateway->pathName($enabledRoom);
        $servicePathName = $gateway->pathName($enabledServiceRoom);

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
            ->assertSee($enabledServiceRoom->name)
            ->assertSee($location->name)
            ->assertDontSee($disabledRoom->name)
            ->assertDontSee($disabledServiceRoom->name)
            ->assertSee('https://cam.example.test/webrtc/'.$pathName.'/', false)
            ->assertSee('https://cam.example.test/webrtc/'.$servicePathName.'/', false)
            ->assertDontSee('rtsp://', false);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://mediamtx.test/v3/config/paths/add/'.$pathName
            && $request['source'] === 'rtsp://user:secret@camera.example.test/live'
            && $request['sourceOnDemand'] === true
            && $request['rtspTransport'] === 'tcp');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://mediamtx.test/v3/config/paths/add/'.$servicePathName
            && $request['source'] === 'rtsp://user:secret@camera.example.test/reception'
            && $request['sourceOnDemand'] === true
            && $request['rtspTransport'] === 'tcp');
        Http::assertSentCount(4);
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

    public function test_camera_gateway_uses_distinct_service_room_paths(): void
    {
        $account = Account::factory()->create(['allow_rtsp_cameras' => true]);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $serviceRoom = ServiceRoom::factory()->for($account)->for($location)->create();
        $gateway = app(MediaMtxCameraGateway::class);

        $this->assertStringContainsString('-r'.$room->id.'-', $gateway->pathName($room));
        $this->assertStringContainsString('-sr'.$serviceRoom->id.'-', $gateway->pathName($serviceRoom));
        $this->assertNotSame($gateway->pathName($room), $gateway->pathName($serviceRoom));
    }
}
