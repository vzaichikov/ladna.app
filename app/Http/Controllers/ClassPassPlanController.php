<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClassPassPlanRequest;
use App\Http\Requests\UpdateClassPassPlanRequest;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Support\ScheduleKindRegistry;
use App\Support\SlugGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ClassPassPlanController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->ensureCurrentUserOwns($account);
        $scheduleKindTabs = $this->scheduleKindTabs($account);
        $activeScheduleKindValue = $this->activeScheduleKindValue($account, $request->query('tab'));

        return view('class-pass-plans.index', [
            'account' => $account,
            'scheduleKindTabs' => $scheduleKindTabs,
            'activeScheduleKindValue' => $activeScheduleKindValue,
            'classPassPlans' => $account->classPassPlans()
                ->with(['classTypes', 'trainerTypes', 'rooms'])
                ->whereHas('classTypes', fn (Builder $query) => $query->where('schedule_kind', $activeScheduleKindValue))
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
        $validated = $this->withPricingAttributes($request->validated(), $request->boolean('allows_any_time'));
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $classPassPlan = $account->classPassPlans()->create($this->classPassPlanAttributes($validated));
        $classPassPlan->classTypes()->sync($validated['class_type_ids']);
        $classPassPlan->trainerTypes()->sync($validated['trainer_type_ids'] ?? []);
        $classPassPlan->rooms()->sync($validated['room_ids'] ?? []);

        return redirect()->route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => $this->scheduleKindValueForClassTypeIds($account, $validated['class_type_ids'])])
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
        $classPassPlan->loadMissing(['classTypes', 'trainerTypes', 'rooms']);

        return view('class-pass-plans.edit', [
            'account' => $account,
            'classPassPlan' => $classPassPlan,
            ...$this->formData($account),
        ]);
    }

    public function update(UpdateClassPassPlanRequest $request, Account $account, ClassPassPlan $classPassPlan): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $classPassPlan);

        $validated = $this->withPricingAttributes($request->validated(), $request->boolean('allows_any_time'));
        $validated['slug'] = $this->uniqueSlug($account, ($validated['slug'] ?? null) ?: $validated['name'], $classPassPlan);
        $validated['is_active'] = $request->boolean('is_active');

        $classPassPlan->update($this->classPassPlanAttributes($validated));
        $classPassPlan->classTypes()->sync($validated['class_type_ids']);
        $classPassPlan->trainerTypes()->sync($validated['trainer_type_ids'] ?? []);
        $classPassPlan->rooms()->sync($validated['room_ids'] ?? []);

        return redirect()->route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => $this->scheduleKindValueForClassTypeIds($account, $validated['class_type_ids'])])
            ->with('status', __('app.class_pass_plan_updated'));
    }

    public function copy(Account $account, ClassPassPlan $classPassPlan): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $classPassPlan);
        $this->ensureCurrentUserOwns($account);

        $classPassPlan->loadMissing(['activityDirections', 'classTypes', 'trainerTypes', 'rooms']);

        DB::transaction(function () use ($account, $classPassPlan): void {
            $copyName = $this->copyName($classPassPlan->name);
            $copy = $classPassPlan->replicate(['slug']);
            $copy->name = $copyName;
            $copy->slug = $this->uniqueSlug($account, $copyName);
            $copy->save();

            $copy->activityDirections()->sync($classPassPlan->activityDirections->modelKeys());
            $copy->classTypes()->sync($classPassPlan->classTypes->modelKeys());
            $copy->trainerTypes()->sync($classPassPlan->trainerTypes->modelKeys());
            $copy->rooms()->sync($classPassPlan->rooms->modelKeys());
        });

        return redirect()->route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => $this->activeScheduleKindValue($account, request()->query('tab'))])
            ->with('status', __('app.class_pass_plan_copied'));
    }

    public function destroy(Account $account, ClassPassPlan $classPassPlan): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $classPassPlan);
        $this->ensureCurrentUserOwns($account);

        $classPassPlan->delete();

        return redirect()->route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => $this->activeScheduleKindValue($account, request()->query('tab'))])
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
        return SlugGenerator::unique($source, 'class-pass', fn (string $candidate): bool => $account->classPassPlans()
            ->where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists());
    }

    private function copyName(string $name): string
    {
        return __('app.copy_prefix').' '.$name;
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

    private function activeScheduleKindValue(Account $account, mixed $requestedValue): string
    {
        $tabs = $this->scheduleKindTabs($account);
        $value = is_string($requestedValue) ? $requestedValue : null;

        if ($value && array_key_exists($value, $tabs)) {
            return $value;
        }

        return array_key_first($tabs) ?? ScheduleKindRegistry::defaultEnabledValues()[0];
    }

    /**
     * @param  array<int, mixed>  $classTypeIds
     */
    private function scheduleKindValueForClassTypeIds(Account $account, array $classTypeIds): string
    {
        $scheduleKindValues = $account->classTypes()
            ->whereKey($classTypeIds)
            ->pluck('schedule_kind')
            ->all();

        return collect(ScheduleKindRegistry::defaultEnabledValues())
            ->first(fn (string $value): bool => in_array($value, $scheduleKindValues, true))
            ?? $this->activeScheduleKindValue($account, null);
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
            'allows_any_time',
            'any_time_addon_price_cents',
            'is_trial',
            'is_active',
            'sort_order',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withPricingAttributes(array $validated, bool $allowsAnyTime): array
    {
        $validated['price_cents'] = $this->moneyToCents($validated['price']);
        $validated['allows_any_time'] = $allowsAnyTime;
        $validated['any_time_addon_price_cents'] = $allowsAnyTime
            ? $this->moneyToCents($validated['any_time_addon_price'])
            : null;
        $validated['is_trial'] = (bool) ($validated['is_trial'] ?? false);

        return $validated;
    }

    private function moneyToCents(mixed $amount): int
    {
        $amount = trim((string) $amount);
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Account $account): array
    {
        $account->ensureDefaultTrainerType();

        return [
            'classTypes' => $account->classTypes()
                ->whereIn('schedule_kind', $account->enabledScheduleKindValues())
                ->orderBy('schedule_kind')
                ->orderBy('name')
                ->get(),
            'trainerTypes' => $account->trainerTypes()->ordered()->get(),
            'rooms' => $account->rooms()->with('location:id,name')->orderBy('location_id')->orderBy('name')->get(),
            'currencies' => config('charm.currencies'),
        ];
    }
}
