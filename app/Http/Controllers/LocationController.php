<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Account;
use App\Models\Location;
use App\Support\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('view', $account);

        $locations = $account->locations()
            ->orderBy('name')
            ->get();

        return view('locations.index', [
            'account' => $account,
            'locations' => $locations,
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('update', $account);

        return view('locations.create', [
            'account' => $account,
            'location' => new Location([
                'is_active' => true,
                'timezone' => $account->timezone,
            ]),
        ]);
    }

    public function store(StoreLocationRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $account->locations()->create($validated);

        return redirect()->route('dashboard.accounts.locations.index', $account)
            ->with('status', __('app.location_created'));
    }

    public function show(Account $account, Location $location): never
    {
        abort(404);
    }

    public function edit(Account $account, Location $location): View
    {
        $this->ensureLocationBelongsToAccount($account, $location);
        $this->authorize('update', $location);

        return view('locations.edit', [
            'account' => $account,
            'location' => $location,
        ]);
    }

    public function update(UpdateLocationRequest $request, Account $account, Location $location): RedirectResponse
    {
        $this->ensureLocationBelongsToAccount($account, $location);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name'], $location);
        $validated['is_active'] = $request->boolean('is_active');

        $location->update($validated);

        return redirect()->route('dashboard.accounts.locations.index', $account)
            ->with('status', __('app.location_updated'));
    }

    public function destroy(Account $account, Location $location): RedirectResponse
    {
        $this->ensureLocationBelongsToAccount($account, $location);
        $this->authorize('delete', $location);

        $location->delete();

        return redirect()->route('dashboard.accounts.locations.index', $account)
            ->with('status', __('app.location_deleted'));
    }

    private function ensureLocationBelongsToAccount(Account $account, Location $location): void
    {
        abort_unless($location->account_id === $account->id, 404);
    }

    private function uniqueSlug(Account $account, string $source, ?Location $ignore = null): string
    {
        return SlugGenerator::unique($source, 'location', fn (string $candidate): bool => $account->locations()
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists());
    }
}
