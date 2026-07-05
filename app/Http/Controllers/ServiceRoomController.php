<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRoomRequest;
use App\Http\Requests\UpdateServiceRoomRequest;
use App\Models\Account;
use App\Models\ServiceRoom;
use App\Support\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ServiceRoomController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('view', $account);
        $this->ensureRtspAllowed($account);

        return view('service-rooms.index', [
            'account' => $account,
            'serviceRooms' => $account->serviceRooms()->with('location')->orderBy('name')->get(),
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('update', $account);
        $this->ensureRtspAllowed($account);

        return view('service-rooms.create', [
            'account' => $account,
            'serviceRoom' => new ServiceRoom(['is_active' => true]),
            'locations' => $account->locations()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreServiceRoomRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug((int) $validated['location_id'], ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['rtsp_enabled'] = $request->boolean('rtsp_enabled');
        $validated['rtsp_url'] = blank($validated['rtsp_url'] ?? null) ? null : $validated['rtsp_url'];

        $account->serviceRooms()->create($validated);

        return redirect()->route('dashboard.accounts.service-rooms.index', $account)
            ->with('status', __('app.service_room_created'));
    }

    public function show(Account $account, ServiceRoom $serviceRoom): never
    {
        abort(404);
    }

    public function edit(Account $account, ServiceRoom $serviceRoom): View
    {
        $this->ensureBelongsToAccount($account, $serviceRoom);
        $this->authorize('update', $account);
        $this->ensureRtspAllowed($account);

        return view('service-rooms.edit', [
            'account' => $account,
            'serviceRoom' => $serviceRoom,
            'locations' => $account->locations()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateServiceRoomRequest $request, Account $account, ServiceRoom $serviceRoom): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $serviceRoom);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug((int) $validated['location_id'], ($validated['slug'] ?? null) ?: $validated['name'], $serviceRoom);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['rtsp_enabled'] = $request->boolean('rtsp_enabled');
        $validated['rtsp_url'] = blank($validated['rtsp_url'] ?? null) ? null : $validated['rtsp_url'];

        $serviceRoom->update($validated);

        return redirect()->route('dashboard.accounts.service-rooms.index', $account)
            ->with('status', __('app.service_room_updated'));
    }

    public function destroy(Account $account, ServiceRoom $serviceRoom): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $serviceRoom);
        $this->authorize('update', $account);
        $this->ensureRtspAllowed($account);

        $serviceRoom->delete();

        return redirect()->route('dashboard.accounts.service-rooms.index', $account)
            ->with('status', __('app.service_room_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, ServiceRoom $serviceRoom): void
    {
        abort_unless($serviceRoom->account_id === $account->id, 404);
    }

    private function ensureRtspAllowed(Account $account): void
    {
        abort_unless($account->allowsRtspCameras(), 404);
    }

    private function uniqueSlug(int $locationId, string $source, ?ServiceRoom $ignore = null): string
    {
        return SlugGenerator::unique($source, 'service-room', fn (string $candidate): bool => ServiceRoom::where('location_id', $locationId)
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists());
    }
}
