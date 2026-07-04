<?php

namespace App\Http\Controllers;

use App\Http\Requests\TestRoomCameraRequest;
use App\Models\Account;
use App\Support\RtspCameraService;
use Illuminate\Http\RedirectResponse;

class RoomCameraTestController extends Controller
{
    public function __invoke(TestRoomCameraRequest $request, Account $account, RtspCameraService $cameras): RedirectResponse
    {
        $result = $cameras->test((string) $request->validated('rtsp_url'));

        return back()
            ->withInput()
            ->with('rtsp_camera_test', $result);
    }
}
