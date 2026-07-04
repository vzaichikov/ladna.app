<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Room;
use App\Support\MediaMtxCameraGateway;
use Illuminate\View\View;
use Throwable;

class CameraController extends Controller
{
    public function index(Account $account, MediaMtxCameraGateway $gateway): View
    {
        $this->authorize('viewReports', $account);
        abort_unless($account->allowsRtspCameras(), 404);

        $gatewayConfigured = $gateway->configured();

        $rooms = $account->rooms()
            ->with('location')
            ->active()
            ->rtspEnabled()
            ->orderBy('name')
            ->get()
            ->map(function (Room $room) use ($gateway, $gatewayConfigured): array {
                if (! $gatewayConfigured) {
                    return [
                        'room' => $room,
                        'playerUrl' => null,
                        'available' => false,
                    ];
                }

                try {
                    $gateway->ensurePath($room);

                    return [
                        'room' => $room,
                        'playerUrl' => $gateway->playerUrl($room),
                        'available' => true,
                    ];
                } catch (Throwable $exception) {
                    report($exception);

                    return [
                        'room' => $room,
                        'playerUrl' => null,
                        'available' => false,
                    ];
                }
            });

        return view('cameras.index', [
            'account' => $account,
            'gatewayConfigured' => $gatewayConfigured,
            'playback' => $gateway->playback(),
            'streams' => $rooms,
        ]);
    }
}
