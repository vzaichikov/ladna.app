<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Room;
use App\Support\RtspCameraService;
use Illuminate\Http\Response;
use Illuminate\Http\StreamedResponse;
use Illuminate\View\View;
use RuntimeException;

class CameraController extends Controller
{
    public function index(Account $account, RtspCameraService $cameras): View
    {
        $this->authorize('viewReports', $account);
        abort_unless($account->allowsRtspCameras(), 404);

        return view('reports.cameras', [
            'account' => $account,
            'ffmpegAvailable' => $cameras->ffmpegAvailable(),
            'rooms' => $account->rooms()
                ->with('location')
                ->active()
                ->rtspEnabled()
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function stream(Account $account, Room $room, RtspCameraService $cameras): StreamedResponse|Response
    {
        $this->ensureBelongsToAccount($account, $room);
        $this->authorize('viewReports', $account);
        abort_unless($account->allowsRtspCameras() && $room->hasEnabledRtspCamera(), 404);

        try {
            return $cameras->stream($room);
        } catch (RuntimeException) {
            return response(__('app.rtsp_camera_stream_unavailable'), 503);
        }
    }

    private function ensureBelongsToAccount(Account $account, Room $room): void
    {
        abort_unless($room->account_id === $account->id, 404);
    }
}
