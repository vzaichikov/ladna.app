<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Room;
use App\Models\ServiceRoom;
use App\Support\MediaMtxCameraGateway;
use App\Support\PeopleCounter\PeopleCounterImageLocator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class CameraController extends Controller
{
    public function index(Account $account, MediaMtxCameraGateway $gateway, PeopleCounterImageLocator $imageLocator): View
    {
        $this->authorize('viewReports', $account);
        abort_unless($account->allowsRtspCameras(), 404);

        $gatewayConfigured = $account->isReadOnlyDemo() || $gateway->configured();

        $roomStreams = $account->rooms()
            ->with(['account', 'location', 'latestSuccessfulPeopleCounterSample'])
            ->active()
            ->rtspEnabled()
            ->orderBy('name')
            ->get()
            ->map(fn (Room $room): array => $this->stream($account, $room, 'room', $gateway, $imageLocator, $gatewayConfigured));
        $serviceRoomStreams = $account->serviceRooms()
            ->with(['account', 'location'])
            ->active()
            ->rtspEnabled()
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceRoom $serviceRoom): array => $this->stream($account, $serviceRoom, 'service_room', $gateway, $imageLocator, $gatewayConfigured));
        $streams = $roomStreams
            ->merge($serviceRoomStreams)
            ->sortBy(fn (array $stream): string => Str::lower(($stream['camera']->location?->name ?? '').'|'.$stream['camera']->name.'|'.$stream['type']))
            ->values();

        return view('cameras.index', [
            'account' => $account,
            'gatewayConfigured' => $gatewayConfigured,
            'playback' => $gateway->playback(),
            'streams' => $streams,
        ]);
    }

    /**
     * @return array{camera: Room|ServiceRoom, type: string, playerUrl: string|null, mockImageUrl: string|null, available: bool}
     */
    private function stream(
        Account $account,
        Room|ServiceRoom $camera,
        string $type,
        MediaMtxCameraGateway $gateway,
        PeopleCounterImageLocator $imageLocator,
        bool $gatewayConfigured,
    ): array {
        if ($account->isReadOnlyDemo()) {
            $sample = $camera instanceof Room ? $camera->latestSuccessfulPeopleCounterSample : null;
            $mockImageUrl = $sample && $imageLocator->exists($account, $sample->original_image_path)
                ? route('dashboard.accounts.people-counter-samples.image', [$account, $sample, 'original'])
                : null;

            return [
                'camera' => $camera,
                'type' => $type,
                'playerUrl' => null,
                'mockImageUrl' => $mockImageUrl,
                'available' => $mockImageUrl !== null,
            ];
        }

        if (! $gatewayConfigured) {
            return [
                'camera' => $camera,
                'type' => $type,
                'playerUrl' => null,
                'mockImageUrl' => null,
                'available' => false,
            ];
        }

        try {
            $gateway->ensurePath($camera);

            return [
                'camera' => $camera,
                'type' => $type,
                'playerUrl' => $gateway->playerUrl($camera),
                'mockImageUrl' => null,
                'available' => true,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'camera' => $camera,
                'type' => $type,
                'playerUrl' => null,
                'mockImageUrl' => null,
                'available' => false,
            ];
        }
    }
}
