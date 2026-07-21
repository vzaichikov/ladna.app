<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
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
        $account->loadMissing('subscription');
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['billing_activation_pending'] = false;

        if ($this->requiresPaidActivation($account, $validated['is_active'])) {
            $validated['is_active'] = false;
            $validated['billing_activation_pending'] = true;
        }

        $account->locations()->create($validated);
        $this->syncTrialQuantity($account);

        return redirect()->route('dashboard.accounts.locations.index', $account)
            ->with('status', $validated['billing_activation_pending']
                ? __('app.location_created_pending_billing')
                : __('app.location_created'));
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
        $account->loadMissing('subscription');

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name'], $location);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['billing_activation_pending'] = false;

        if (! $location->is_active && $this->requiresPaidActivation($account, $validated['is_active'])) {
            $validated['is_active'] = false;
            $validated['billing_activation_pending'] = true;
        }

        $location->update($validated);
        $this->syncTrialQuantity($account);

        return redirect()->route('dashboard.accounts.locations.index', $account)
            ->with('status', $validated['billing_activation_pending']
                ? __('app.location_activation_pending_billing')
                : __('app.location_updated'));
    }

    public function destroy(Account $account, Location $location): RedirectResponse
    {
        $this->ensureLocationBelongsToAccount($account, $location);
        $this->authorize('delete', $location);

        $location->delete();
        $account->loadMissing('subscription');
        $this->syncTrialQuantity($account);

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

    private function requiresPaidActivation(Account $account, bool $requestedActive): bool
    {
        $subscription = $account->subscription;

        if (! $requestedActive || ! $subscription?->usesLocationBilling()) {
            return false;
        }

        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
            return false;
        }

        $targetQuantity = $account->locations()->active()->count() + 1;

        return $targetQuantity > max(1, (int) $subscription->billable_location_count);
    }

    private function syncTrialQuantity(Account $account): void
    {
        $subscription = $account->subscription;

        if (! $subscription?->usesLocationBilling() || $subscription->status !== SubscriptionStatus::Trialing) {
            return;
        }

        $subscription->forceFill([
            'billable_location_count' => max(1, $account->locations()->active()->count()),
        ])->save();
    }
}
