<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClassPassPlanRequest;
use App\Http\Requests\UpdateClassPassPlanRequest;
use App\Models\Account;
use App\Models\ClassPassPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClassPassPlanController extends Controller
{
    public function index(Account $account): View
    {
        $this->ensureCurrentUserOwns($account);

        return view('class-pass-plans.index', [
            'account' => $account,
            'classPassPlans' => $account->classPassPlans()
                ->with('activityDirections')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(Account $account): View
    {
        $this->ensureCurrentUserOwns($account);

        return view('class-pass-plans.create', [
            'account' => $account,
            'classPassPlan' => new ClassPassPlan([
                'currency' => $account->default_currency,
                'validity_days' => 30,
                'is_active' => true,
            ]),
            ...$this->formData($account),
        ]);
    }

    public function store(StoreClassPassPlanRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $classPassPlan = $account->classPassPlans()->create($this->classPassPlanAttributes($validated));
        $classPassPlan->activityDirections()->sync($validated['activity_direction_ids']);

        return redirect()->route('dashboard.accounts.class-pass-plans.index', $account)
            ->with('status', __('app.class_pass_plan_created'));
    }

    public function show(Account $account, ClassPassPlan $classPassPlan): never
    {
        abort(404);
    }

    public function edit(Account $account, ClassPassPlan $classPassPlan): View
    {
        $this->ensureBelongsToAccount($account, $classPassPlan);
        $this->ensureCurrentUserOwns($account);
        $classPassPlan->loadMissing('activityDirections');

        return view('class-pass-plans.edit', [
            'account' => $account,
            'classPassPlan' => $classPassPlan,
            ...$this->formData($account),
        ]);
    }

    public function update(UpdateClassPassPlanRequest $request, Account $account, ClassPassPlan $classPassPlan): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $classPassPlan);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name'], $classPassPlan);
        $validated['is_active'] = $request->boolean('is_active');

        $classPassPlan->update($this->classPassPlanAttributes($validated));
        $classPassPlan->activityDirections()->sync($validated['activity_direction_ids']);

        return redirect()->route('dashboard.accounts.class-pass-plans.index', $account)
            ->with('status', __('app.class_pass_plan_updated'));
    }

    public function destroy(Account $account, ClassPassPlan $classPassPlan): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $classPassPlan);
        $this->ensureCurrentUserOwns($account);

        $classPassPlan->delete();

        return redirect()->route('dashboard.accounts.class-pass-plans.index', $account)
            ->with('status', __('app.class_pass_plan_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, ClassPassPlan $classPassPlan): void
    {
        abort_unless($classPassPlan->account_id === $account->id, 404);
    }

    private function ensureCurrentUserOwns(Account $account): void
    {
        abort_unless($account->isOwnedBy(request()->user()), 403);
    }

    private function uniqueSlug(Account $account, string $source, ?ClassPassPlan $ignore = null): string
    {
        $slug = Str::slug($source) ?: 'class-pass';
        $candidate = $slug;
        $suffix = 2;

        while ($account->classPassPlans()
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function classPassPlanAttributes(array $validated): array
    {
        return Arr::only($validated, [
            'name',
            'slug',
            'description',
            'price_cents',
            'currency',
            'sessions_count',
            'validity_days',
            'available_from_time',
            'available_until_time',
            'is_active',
            'sort_order',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Account $account): array
    {
        return [
            'activityDirections' => $account->activityDirections()->orderBy('name')->get(),
            'currencies' => config('charm.currencies'),
        ];
    }
}
