<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreActivityDirectionRequest;
use App\Http\Requests\UpdateActivityDirectionRequest;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Support\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ActivityDirectionController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('view', $account);

        return view('activity-directions.index', [
            'account' => $account,
            'activityDirections' => $account->activityDirections()->orderBy('name')->get(),
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('update', $account);

        return view('activity-directions.create', [
            'account' => $account,
            'activityDirection' => new ActivityDirection(['is_active' => true]),
        ]);
    }

    public function store(StoreActivityDirectionRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $account->activityDirections()->create($validated);

        return redirect()->route('dashboard.accounts.activity-directions.index', $account)
            ->with('status', __('app.activity_direction_created'));
    }

    public function show(Account $account, ActivityDirection $activityDirection): never
    {
        abort(404);
    }

    public function edit(Account $account, ActivityDirection $activityDirection): View
    {
        $this->ensureBelongsToAccount($account, $activityDirection);
        $this->authorize('update', $account);

        return view('activity-directions.edit', [
            'account' => $account,
            'activityDirection' => $activityDirection,
        ]);
    }

    public function update(UpdateActivityDirectionRequest $request, Account $account, ActivityDirection $activityDirection): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $activityDirection);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name'], $activityDirection);
        $validated['is_active'] = $request->boolean('is_active');

        $activityDirection->update($validated);

        return redirect()->route('dashboard.accounts.activity-directions.index', $account)
            ->with('status', __('app.activity_direction_updated'));
    }

    public function copy(Account $account, ActivityDirection $activityDirection): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $activityDirection);
        $this->authorize('update', $account);

        $copyName = $this->copyName($activityDirection->name);
        $copy = $activityDirection->replicate(['slug']);
        $copy->name = $copyName;
        $copy->slug = $this->uniqueSlug($account, $copyName);
        $copy->save();

        return redirect()->route('dashboard.accounts.activity-directions.index', $account)
            ->with('status', __('app.activity_direction_copied'));
    }

    public function destroy(Account $account, ActivityDirection $activityDirection): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $activityDirection);
        $this->authorize('update', $account);

        $activityDirection->delete();

        return redirect()->route('dashboard.accounts.activity-directions.index', $account)
            ->with('status', __('app.activity_direction_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, ActivityDirection $activityDirection): void
    {
        abort_unless($activityDirection->account_id === $account->id, 404);
    }

    private function uniqueSlug(Account $account, string $source, ?ActivityDirection $ignore = null): string
    {
        return SlugGenerator::unique($source, 'direction', fn (string $candidate): bool => $account->activityDirections()
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists());
    }

    private function copyName(string $name): string
    {
        return __('app.copy_prefix').' '.$name;
    }
}
