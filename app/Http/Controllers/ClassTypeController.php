<?php

namespace App\Http\Controllers;

use App\Enums\ScheduleKind;
use App\Http\Requests\StoreClassTypeRequest;
use App\Http\Requests\UpdateClassTypeRequest;
use App\Models\Account;
use App\Models\ClassType;
use App\Support\ScheduleKindRegistry;
use App\Support\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ClassTypeController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('view', $account);
        $scheduleKind = $this->currentScheduleKind();
        $this->ensureScheduleKindEnabled($account, $scheduleKind);

        return view('class-types.index', [
            'account' => $account,
            'classTypes' => $account->classTypes()
                ->with('activityDirection')
                ->where('schedule_kind', $scheduleKind->value)
                ->orderBy('name')
                ->get(),
            'scheduleKind' => $scheduleKind,
            'scheduleKindDefinition' => ScheduleKindRegistry::get($scheduleKind),
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('update', $account);
        $scheduleKind = $this->currentScheduleKind();
        $this->ensureScheduleKindEnabled($account, $scheduleKind);

        return view('class-types.create', [
            'account' => $account,
            'classType' => new ClassType([
                'schedule_kind' => $scheduleKind,
                'default_duration_minutes' => 60,
                'is_active' => true,
            ]),
            'activityDirections' => $account->activityDirections()->active()->orderBy('name')->get(),
            'scheduleKind' => $scheduleKind,
            'scheduleKindDefinition' => ScheduleKindRegistry::get($scheduleKind),
        ]);
    }

    public function store(StoreClassTypeRequest $request, Account $account): RedirectResponse
    {
        $scheduleKind = $this->currentScheduleKind();
        $this->ensureScheduleKindEnabled($account, $scheduleKind);

        $validated = $request->validated();
        $validated['schedule_kind'] = $scheduleKind->value;
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $account->classTypes()->create($validated);

        return redirect()->route(ScheduleKindRegistry::routeName($scheduleKind, 'index'), $account)
            ->with('status', __('app.class_type_created'));
    }

    public function show(Account $account, ClassType $classType): never
    {
        abort(404);
    }

    public function edit(Account $account, ClassType $classType): View
    {
        $scheduleKind = $this->currentScheduleKind();
        $this->ensureBelongsToAccount($account, $classType);
        $this->ensureClassTypeMatchesScheduleKind($classType, $scheduleKind);
        $this->ensureScheduleKindEnabled($account, $scheduleKind);
        $this->authorize('update', $account);

        return view('class-types.edit', [
            'account' => $account,
            'classType' => $classType,
            'activityDirections' => $account->activityDirections()->active()->orderBy('name')->get(),
            'scheduleKind' => $scheduleKind,
            'scheduleKindDefinition' => ScheduleKindRegistry::get($scheduleKind),
        ]);
    }

    public function update(UpdateClassTypeRequest $request, Account $account, ClassType $classType): RedirectResponse
    {
        $scheduleKind = $this->currentScheduleKind();
        $this->ensureBelongsToAccount($account, $classType);
        $this->ensureClassTypeMatchesScheduleKind($classType, $scheduleKind);
        $this->ensureScheduleKindEnabled($account, $scheduleKind);

        $validated = $request->validated();
        $validated['schedule_kind'] = $scheduleKind->value;
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name'], $classType);
        $validated['is_active'] = $request->boolean('is_active');

        $classType->update($validated);

        return redirect()->route(ScheduleKindRegistry::routeName($scheduleKind, 'index'), $account)
            ->with('status', __('app.class_type_updated'));
    }

    public function copy(Account $account, ClassType $classType): RedirectResponse
    {
        $scheduleKind = $this->currentScheduleKind();
        $this->ensureBelongsToAccount($account, $classType);
        $this->ensureClassTypeMatchesScheduleKind($classType, $scheduleKind);
        $this->ensureScheduleKindEnabled($account, $scheduleKind);
        $this->authorize('update', $account);

        $copyName = $this->copyName($classType->name);
        $copy = $classType->replicate(['slug']);
        $copy->name = $copyName;
        $copy->slug = $this->uniqueSlug($account, $copyName);
        $copy->save();

        return redirect()->route(ScheduleKindRegistry::routeName($scheduleKind, 'index'), $account)
            ->with('status', __('app.class_type_copied'));
    }

    public function destroy(Account $account, ClassType $classType): RedirectResponse
    {
        $scheduleKind = $this->currentScheduleKind();
        $this->ensureBelongsToAccount($account, $classType);
        $this->ensureClassTypeMatchesScheduleKind($classType, $scheduleKind);
        $this->ensureScheduleKindEnabled($account, $scheduleKind);
        $this->authorize('update', $account);

        $classType->scheduleSeries()->with('scheduledClasses')->get()
            ->each(fn ($scheduleSeries) => $scheduleSeries->scheduledClasses()->delete());
        $classType->scheduledClasses()->delete();
        $classType->delete();

        return redirect()->route(ScheduleKindRegistry::routeName($scheduleKind, 'index'), $account)
            ->with('status', __('app.class_type_deleted'));
    }

    private function currentScheduleKind(): ScheduleKind
    {
        return ScheduleKind::tryFrom((string) request()->route('schedule_kind')) ?? ScheduleKind::GroupClass;
    }

    private function ensureBelongsToAccount(Account $account, ClassType $classType): void
    {
        abort_unless($classType->account_id === $account->id, 404);
    }

    private function ensureClassTypeMatchesScheduleKind(ClassType $classType, ScheduleKind $scheduleKind): void
    {
        abort_unless($classType->schedule_kind === $scheduleKind, 404);
    }

    private function ensureScheduleKindEnabled(Account $account, ScheduleKind $scheduleKind): void
    {
        abort_unless($account->hasScheduleKindEnabled($scheduleKind), 404);
    }

    private function uniqueSlug(Account $account, string $source, ?ClassType $ignore = null): string
    {
        return SlugGenerator::unique($source, 'class-type', fn (string $candidate): bool => $account->classTypes()
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists());
    }

    private function copyName(string $name): string
    {
        return __('app.copy_prefix').' '.$name;
    }
}
