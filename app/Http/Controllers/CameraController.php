<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Room;
use App\Models\ServiceRoom;
use App\Support\MediaMtxCameraGateway;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class CameraController extends Controller
{
    public function index(Account $account, MediaMtxCameraGateway $gateway): View
    {
        $this->authorize('viewReports', $account);
        abort_unless($account->allowsRtspCameras(), 404);

        $gatewayConfigured = $gateway->configured();

        $roomStreams = $account->rooms()
            ->with('location')
            ->active()
            ->rtspEnabled()
            ->orderBy('name')
            ->get()
            ->map(fn (Room $room): array => $this->stream($room, 'room', $gateway, $gatewayConfigured));
        $serviceRoomStreams = $account->serviceRooms()
            ->with('location')
            ->active()
            ->rtspEnabled()
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceRoom $serviceRoom): array => $this->stream($serviceRoom, 'service_room', $gateway, $gatewayConfigured));
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
     * @return array{camera: Room|ServiceRoom, type: string, playerUrl: string|null, available: bool}
     */
    private function stream(Room|ServiceRoom $camera, string $type, MediaMtxCameraGateway $gateway, bool $gatewayConfigured): array
    {
        if (! $gatewayConfigured) {
            return [
                'camera' => $camera,
                'type' => $type,
                'playerUrl' => null,
                'available' => false,
            ];
        }

        try {
            $gateway->ensurePath($camera);

            return [
                'camera' => $camera,
                'type' => $type,
                'playerUrl' => $gateway->playerUrl($camera),
                'available' => true,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'camera' => $camera,
                'type' => $type,
                'playerUrl' => null,
                'available' => false,
            ];
        }
    }
}
