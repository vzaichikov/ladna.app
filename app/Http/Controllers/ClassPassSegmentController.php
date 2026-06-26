<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClassPassSegmentRequest;
use App\Http\Requests\UpdateClassPassSegmentRequest;
use App\Models\Account;
use App\Models\ClassPassSegment;
use App\Support\ScheduleKindRegistry;
use App\Support\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ClassPassSegmentController extends Controller
{
    public function index(Account $account): View
    {
        $this->ensureCurrentUserOwns($account);

        return view('class-pass-segments.index', [
            'account' => $account,
            'classPassSegments' => $account->classPassSegments()
                ->with('activityDirections')
                ->withCount('classPassPlans')
                ->ordered()
                ->get(),
            'scheduleKindTabs' => $this->scheduleKindTabs($account),
        ]);
    }

    public function create(Account $account): View
    {
        $this->ensureCurrentUserOwns($account);

        return view('class-pass-segments.create', [
            'account' => $account,
            'classPassSegment' => new ClassPassSegment([
                'schedule_kind' => $account->enabledScheduleKindValues()[0] ?? 'group_class',
                'is_active' => true,
                'sort_order' => 0,
            ]),
            ...$this->formData($account),
        ]);
    }

    public function store(StoreClassPassSegmentRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $classPassSegment = $account->classPassSegments()->create($this->segmentAttributes($validated));
        $classPassSegment->activityDirections()->sync($validated['activity_direction_ids'] ?? []);

        return redirect()->route('dashboard.accounts.class-pass-segments.index', $account)
            ->with('status', __('app.class_pass_segment_created'));
    }

    public function show(Account $account, ClassPassSegment $classPassSegment): never
    {
        abort(404);
    }

    public function edit(Account $account, ClassPassSegment $classPassSegment): View
    {
        $this->ensureBelongsToAccount($account, $classPassSegment);
        $this->ensureCurrentUserOwns($account);
        $classPassSegment->loadMissing('activityDirections');

        return view('class-pass-segments.edit', [
            'account' => $account,
            'classPassSegment' => $classPassSegment,
            ...$this->formData($account),
        ]);
    }

    public function update(UpdateClassPassSegmentRequest $request, Account $account, ClassPassSegment $classPassSegment): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $classPassSegment);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name'], $classPassSegment);
        $validated['is_active'] = $request->boolean('is_active');

        $classPassSegment->update($this->segmentAttributes($validated));
        $classPassSegment->activityDirections()->sync($validated['activity_direction_ids'] ?? []);

        return redirect()->route('dashboard.accounts.class-pass-segments.index', $account)
            ->with('status', __('app.class_pass_segment_updated'));
    }

    public function destroy(Account $account, ClassPassSegment $classPassSegment): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $classPassSegment);
        $this->ensureCurrentUserOwns($account);

        $classPassSegment->delete();

        return redirect()->route('dashboard.accounts.class-pass-segments.index', $account)
            ->with('status', __('app.class_pass_segment_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, ClassPassSegment $classPassSegment): void
    {
        abort_unless($classPassSegment->account_id === $account->id, 404);
    }

    private function ensureCurrentUserOwns(Account $account): void
    {
        abort_unless($account->isOwnedBy(request()->user()), 403);
    }

    private function uniqueSlug(Account $account, string $source, ?ClassPassSegment $ignore = null): string
    {
        return SlugGenerator::unique($source, 'class-pass-segment', fn (string $candidate): bool => $account->classPassSegments()
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function scheduleKindTabs(Account $account): array
    {
        return collect(ScheduleKindRegistry::all())
            ->filter(fn (array $definition, string $value): bool => $account->hasScheduleKindEnabled($value))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Account $account): array
    {
        return [
            'scheduleKindTabs' => $this->scheduleKindTabs($account),
            'activityDirections' => $account->activityDirections()->active()->orderBy('name')->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function segmentAttributes(array $validated): array
    {
        return collect($validated)
            ->only(['schedule_kind', 'name', 'slug', 'sort_order', 'is_active'])
            ->all();
    }
}
