<?php

namespace App\Http\Controllers\Platform;

use App\Enums\SubscriptionPlanType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionPlanRequest;
use App\Http\Requests\UpdateSubscriptionPlanRequest;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanSmsRateChange;
use App\Models\SubscriptionPriceVersion;
use App\Support\Payments\PaymentAmounts;
use App\Support\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SubscriptionPlanController extends Controller
{
    public function index(): View
    {
        $plans = SubscriptionPlan::withCount(['subscriptions', 'subscriptionPayments', 'priceVersions'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $currentPriceVersions = SubscriptionPriceVersion::query()
            ->published()
            ->effectiveAt(now())
            ->whereIn('subscription_plan_id', $plans->modelKeys())
            ->with('tiers')
            ->get()
            ->unique('subscription_plan_id')
            ->keyBy('subscription_plan_id');

        return view('platform.subscription-plans.index', [
            'plans' => $plans,
            'currentPriceVersions' => $currentPriceVersions,
        ]);
    }

    public function create(): View
    {
        return view('platform.subscription-plans.create', [
            'plan' => new SubscriptionPlan([
                'currency' => 'UAH',
                'billing_interval' => 'monthly',
                'plan_type' => SubscriptionPlanType::Standard,
                'access_days' => 30,
                'requires_recurring_payment' => true,
                'renewal_lead_days' => 2,
                'is_active' => true,
            ]),
        ]);
    }

    public function store(StoreSubscriptionPlanRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->slug($validated);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['public_signup_enabled'] = $request->boolean('public_signup_enabled');
        $validated['requires_recurring_payment'] = $request->boolean('requires_recurring_payment');
        $validated['price_cents'] = PaymentAmounts::decimalToCents($validated['price_uah']);
        $validated['sms_segment_price_cents'] = $this->smsSegmentPriceCents($validated);
        unset($validated['price_uah'], $validated['sms_segment_price_uah']);

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
        $validated['public_signup_enabled'] = $request->boolean('public_signup_enabled');
        $validated['requires_recurring_payment'] = $request->boolean('requires_recurring_payment');
        $validated['price_cents'] = PaymentAmounts::decimalToCents($validated['price_uah']);
        $validated['sms_segment_price_cents'] = $this->smsSegmentPriceCents($validated);
        unset($validated['price_uah'], $validated['sms_segment_price_uah']);

        DB::transaction(function () use ($request, $subscriptionPlan, $validated): void {
            $oldSmsSegmentPriceCents = $subscriptionPlan->sms_segment_price_cents;

            $subscriptionPlan->update($validated);

            if ($oldSmsSegmentPriceCents !== $subscriptionPlan->sms_segment_price_cents) {
                SubscriptionPlanSmsRateChange::create([
                    'subscription_plan_id' => $subscriptionPlan->id,
                    'actor_user_id' => $request->user()?->getKey(),
                    'old_sms_segment_price_cents' => $oldSmsSegmentPriceCents,
                    'new_sms_segment_price_cents' => $subscriptionPlan->sms_segment_price_cents,
                ]);
            }
        });

        return redirect()->route('platform.subscription-plans.index')
            ->with('status', __('app.subscription_plan_updated'));
    }

    public function destroy(SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        if (
            $subscriptionPlan->subscriptions()->exists()
            || $subscriptionPlan->subscriptionPayments()->exists()
            || $subscriptionPlan->priceVersions()->exists()
            || $subscriptionPlan->smsRateChanges()->exists()
        ) {
            return redirect()->route('platform.subscription-plans.index')
                ->withErrors(['plan' => __('app.subscription_plan_in_use')]);
        }

        $subscriptionPlan->delete();

        return redirect()->route('platform.subscription-plans.index')
            ->with('status', __('app.subscription_plan_deleted'));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function slug(array $validated, ?SubscriptionPlan $ignore = null): string
    {
        return SlugGenerator::unique($validated['slug'] ?: $validated['name'], 'subscription-plan', fn (string $candidate): bool => SubscriptionPlan::where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists());
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function smsSegmentPriceCents(array $validated): ?int
    {
        $amount = $validated['sms_segment_price_uah'] ?? null;

        return filled($amount) ? PaymentAmounts::decimalToCents($amount) : null;
    }
}
