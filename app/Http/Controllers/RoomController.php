<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\Account;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RoomController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('view', $account);

        return view('rooms.index', [
            'account' => $account,
            'rooms' => $account->rooms()->with('location')->orderBy('name')->get(),
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('update', $account);

        return view('rooms.create', [
            'account' => $account,
            'room' => new Room(['is_active' => true]),
            'locations' => $account->locations()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreRoomRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug((int) $validated['location_id'], ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $account->rooms()->create($validated);

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

        return view('rooms.edit', [
            'account' => $account,
            'room' => $room,
            'locations' => $account->locations()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateRoomRequest $request, Account $account, Room $room): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $room);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug((int) $validated['location_id'], ($validated['slug'] ?? null) ?: $validated['name'], $room);
        $validated['is_active'] = $request->boolean('is_active');

        $room->update($validated);

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
        $slug = Str::slug($source) ?: 'room';
        $candidate = $slug;
        $suffix = 2;

        while (Room::where('location_id', $locationId)
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
