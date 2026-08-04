<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\Account;
use App\Models\Room;
use App\Support\SlugGenerator;
use App\Support\WorkingLocationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RoomController extends Controller
{
    public function index(
        Account $account,
        WorkingLocationContext $workingLocationContext,
    ): View {
        $this->authorize('view', $account);
        $selectedLocationId = $workingLocationContext->filterLocationId($account, includeInactive: true);

        return view('rooms.index', [
            'account' => $account,
            'locations' => $account->locations()->orderBy('name')->get(['id', 'name', 'is_active']),
            'selectedLocationId' => $selectedLocationId,
            'rooms' => $account->rooms()
                ->with([
                    'location',
                    'activityDirections' => fn ($query) => $query->orderBy('name'),
                ])
                ->when($selectedLocationId, fn ($query, int $locationId) => $query->where('location_id', $locationId))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(Account $account, WorkingLocationContext $workingLocationContext): View
    {
        $this->authorize('update', $account);

        return view('rooms.create', [
            'account' => $account,
            'room' => new Room([
                'location_id' => $workingLocationContext->formLocationId($account),
                'is_active' => true,
            ]),
            'locations' => $account->locations()->active()->orderBy('name')->get(),
            'availableActivityDirections' => $account->activityDirections()->active()->orderBy('name')->get(),
            'selectedActivityDirectionIds' => [],
        ]);
    }

    public function store(StoreRoomRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug((int) $validated['location_id'], ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        if ($request->user()?->isPlatformAdmin() && $account->allowsRtspCameras()) {
            $validated['rtsp_enabled'] = $request->boolean('rtsp_enabled');
            $validated['rtsp_url'] = blank($validated['rtsp_url'] ?? null) ? null : $validated['rtsp_url'];
            $validated['people_counter_capture_delay_seconds'] = $validated['people_counter_capture_delay_seconds'] ?? null;
        }

        DB::transaction(function () use ($account, $validated): void {
            $room = $account->rooms()->create(Arr::except($validated, 'activity_direction_ids'));

            $room->activityDirections()->syncWithPivotValues(
                $validated['activity_direction_ids'],
                ['account_id' => $account->id],
            );
        });

        return redirect()->route('dashboard.accounts.rooms.index', $account)
            ->with('status', __('app.room_created'));
    }

    public function show(Account $account, Room $room): never
    {
        abort(404);
    }

    public function edit(Account $account, Room $room): View
    {
        $this->ensureBelongsToAccount($account, $room);
        $this->authorize('update', $account);
        $room->loadMissing('activityDirections');
        $availableActivityDirections = $account->activityDirections()
            ->active()
            ->orderBy('name')
            ->get()
            ->concat($room->activityDirections)
            ->unique('id')
            ->sortBy('name')
            ->values();

        return view('rooms.edit', [
            'account' => $account,
            'room' => $room,
            'locations' => $account->locations()
                ->where(fn ($query) => $query->active()->orWhere('locations.id', $room->location_id))
                ->orderBy('name')
                ->get(),
            'availableActivityDirections' => $availableActivityDirections,
            'selectedActivityDirectionIds' => $room->activityDirections->pluck('id')->all(),
        ]);
    }

    public function update(UpdateRoomRequest $request, Account $account, Room $room): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $room);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug((int) $validated['location_id'], ($validated['slug'] ?? null) ?: $validated['name'], $room);
        $validated['is_active'] = $request->boolean('is_active');

        if ($request->user()?->isPlatformAdmin() && $account->allowsRtspCameras()) {
            $validated['rtsp_enabled'] = $request->boolean('rtsp_enabled');
            $validated['rtsp_url'] = blank($validated['rtsp_url'] ?? null) ? null : $validated['rtsp_url'];
            $validated['people_counter_capture_delay_seconds'] = $validated['people_counter_capture_delay_seconds'] ?? null;
        }

        DB::transaction(function () use ($account, $room, $validated): void {
            $room->update(Arr::except($validated, 'activity_direction_ids'));

            $room->activityDirections()->syncWithPivotValues(
                $validated['activity_direction_ids'],
                ['account_id' => $account->id],
            );
        });

        return redirect()->route('dashboard.accounts.rooms.index', $account)
            ->with('status', __('app.room_updated'));
    }

    public function destroy(Account $account, Room $room): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $room);
        $this->authorize('update', $account);

        $room->scheduledClasses()->delete();
        $room->delete();

        return redirect()->route('dashboard.accounts.rooms.index', $account)
            ->with('status', __('app.room_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, Room $room): void
    {
        abort_unless($room->account_id === $account->id, 404);
    }

    private function uniqueSlug(int $locationId, string $source, ?Room $ignore = null): string
    {
        return SlugGenerator::unique($source, 'room', fn (string $candidate): bool => Room::where('location_id', $locationId)
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists());
    }
}
