<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionPlanRequest;
use App\Http\Requests\UpdateSubscriptionPlanRequest;
use App\Models\SubscriptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SubscriptionPlanController extends Controller
{
    public function index(): View
    {
        return view('platform.subscription-plans.index', [
            'plans' => SubscriptionPlan::withCount('subscriptions')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('platform.subscription-plans.create', [
            'plan' => new SubscriptionPlan(['currency' => 'UAH', 'billing_interval' => 'monthly', 'is_active' => true]),
        ]);
    }

    public function store(StoreSubscriptionPlanRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->slug($validated);
        $validated['is_active'] = $request->boolean('is_active');

        SubscriptionPlan::create($validated);

        return redirect()->route('platform.subscription-plans.index')
            ->with('status', __('app.subscription_plan_created'));
    }

    public function show(SubscriptionPlan $subscriptionPlan): never
    {
        abort(404);
    }

    public function edit(SubscriptionPlan $subscriptionPlan): View
    {
        return view('platform.subscription-plans.edit', [
            'plan' => $subscriptionPlan,
        ]);
    }

    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->slug($validated, $subscriptionPlan);
        $validated['is_active'] = $request->boolean('is_active');

        $subscriptionPlan->update($validated);

        return redirect()->route('platform.subscription-plans.index')
            ->with('status', __('app.subscription_plan_updated'));
    }

    public function destroy(SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $subscriptionPlan->delete();

        return redirect()->route('platform.subscription-plans.index')
            ->with('status', __('app.subscription_plan_deleted'));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function slug(array $validated, ?SubscriptionPlan $ignore = null): string
    {
        $slug = Str::slug($validated['slug'] ?: $validated['name']) ?: 'subscription-plan';
        $candidate = $slug;
        $suffix = 2;

        while (SubscriptionPlan::where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
