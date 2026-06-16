<?php

namespace App\Http\Controllers;

use App\Enums\ScheduleKind;
use App\Http\Requests\StoreClassTypeRequest;
use App\Http\Requests\UpdateClassTypeRequest;
use App\Models\Account;
use App\Models\ClassType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClassTypeController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('view', $account);

        return view('class-types.index', [
            'account' => $account,
            'classTypes' => $account->classTypes()->with('activityDirection')->orderBy('name')->get(),
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('update', $account);

        return view('class-types.create', [
            'account' => $account,
            'classType' => new ClassType([
                'schedule_kind' => ScheduleKind::GroupClass,
                'default_duration_minutes' => 60,
                'is_active' => true,
            ]),
            'activityDirections' => $account->activityDirections()->active()->orderBy('name')->get(),
            'scheduleKinds' => ScheduleKind::cases(),
        ]);
    }

    public function store(StoreClassTypeRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $account->classTypes()->create($validated);

        return redirect()->route('dashboard.accounts.class-types.index', $account)
            ->with('status', __('app.class_type_created'));
    }

    public function show(Account $account, ClassType $classType): never
    {
        abort(404);
    }

    public function edit(Account $account, ClassType $classType): View
    {
        $this->ensureBelongsToAccount($account, $classType);
        $this->authorize('update', $account);

        return view('class-types.edit', [
            'account' => $account,
            'classType' => $classType,
            'activityDirections' => $account->activityDirections()->active()->orderBy('name')->get(),
            'scheduleKinds' => ScheduleKind::cases(),
        ]);
    }

    public function update(UpdateClassTypeRequest $request, Account $account, ClassType $classType): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $classType);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name'], $classType);
        $validated['is_active'] = $request->boolean('is_active');

        $classType->update($validated);

        return redirect()->route('dashboard.accounts.class-types.index', $account)
            ->with('status', __('app.class_type_updated'));
    }

    public function destroy(Account $account, ClassType $classType): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $classType);
        $this->authorize('update', $account);

        $classType->scheduleSeries()->with('scheduledClasses')->get()
            ->each(fn ($scheduleSeries) => $scheduleSeries->scheduledClasses()->delete());
        $classType->scheduledClasses()->delete();
        $classType->delete();

        return redirect()->route('dashboard.accounts.class-types.index', $account)
            ->with('status', __('app.class_type_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, ClassType $classType): void
    {
        abort_unless($classType->account_id === $account->id, 404);
    }

    private function uniqueSlug(Account $account, string $source, ?ClassType $ignore = null): string
    {
        $slug = Str::slug($source) ?: 'class-type';
        $candidate = $slug;
        $suffix = 2;

        while ($account->classTypes()
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
