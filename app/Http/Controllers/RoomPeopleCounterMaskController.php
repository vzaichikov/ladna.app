<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRoomPeopleCounterMaskRequest;
use App\Models\Account;
use App\Models\Room;
use App\Support\PeopleCounter\PeopleCounterCaptureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class RoomPeopleCounterMaskController extends Controller
{
    public function edit(Account $account, Room $room): View
    {
        $this->authorizeMaskAccess($account, $room);

        return view('rooms.people-counter-mask', [
            'account' => $account,
            'room' => $room,
            'snapshotUrl' => $room->people_counter_snapshot_path
                ? route('dashboard.accounts.rooms.people-counter-mask.snapshot', [$account, $room])
                : null,
        ]);
    }

    public function capture(Account $account, Room $room, PeopleCounterCaptureService $captureService): RedirectResponse
    {
        $this->authorizeMaskAccess($account, $room);

        try {
            $captureService->captureCalibrationSnapshot($room);
        } catch (Throwable $throwable) {
            report($throwable);

            return back()->with('warning', __('app.people_counter_snapshot_failed', [
                'message' => $throwable->getMessage(),
            ]));
        }

        return redirect()
            ->route('dashboard.accounts.rooms.people-counter-mask.edit', [$account, $room])
            ->with('status', __('app.people_counter_snapshot_captured'));
    }

    public function update(UpdateRoomPeopleCounterMaskRequest $request, Account $account, Room $room): RedirectResponse
    {
        $this->authorizeMaskAccess($account, $room);

        $room->update([
            'people_counter_mask_polygons' => $request->polygons(),
        ]);

        return redirect()
            ->route('dashboard.accounts.rooms.people-counter-mask.edit', [$account, $room])
            ->with('status', __('app.people_counter_mask_saved'));
    }

    public function snapshot(Account $account, Room $room): BinaryFileResponse
    {
        $this->authorizeMaskAccess($account, $room);

        $path = $room->people_counter_snapshot_path;

        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/jpeg']);
    }

    private function authorizeMaskAccess(Account $account, Room $room): void
    {
        abort_unless($room->account_id === $account->id, 404);
        $this->authorize('update', $account);
        abort_unless($account->allowsRtspCameras() && $account->peopleCounterEnabled(), 404);
        abort_unless($room->hasEnabledRtspCamera(), 404);
    }
}
