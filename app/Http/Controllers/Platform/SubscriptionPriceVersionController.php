<?php

namespace App\Http\Controllers\Platform;

use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionPriceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionPriceVersionRequest;
use App\Http\Requests\UpdateSubscriptionPriceVersionRequest;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Support\Payments\PaymentAmounts;
use App\Support\SaasBilling\SubscriptionPricingCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use LogicException;

class SubscriptionPriceVersionController extends Controller
{
    public function index(SubscriptionPlan $subscriptionPlan): View
    {
        return view('platform.subscription-price-versions.index', [
            'plan' => $subscriptionPlan,
            'priceVersions' => $subscriptionPlan->priceVersions()->withCount(['tiers', 'subscriptions', 'payments'])->latest('version')->get(),
        ]);
    }

    public function create(SubscriptionPlan $subscriptionPlan): View
    {
        return view('platform.subscription-price-versions.create', [
            'plan' => $subscriptionPlan,
            'priceVersion' => new SubscriptionPriceVersion([
                'currency' => 'UAH',
                'trial_days' => 30,
                'annual_discount_percent' => 10,
            ]),
            'tiers' => collect([
                ['starts_at_location' => 1, 'ends_at_location' => 1, 'unit_price_cents' => 90_000],
                ['starts_at_location' => 2, 'ends_at_location' => null, 'unit_price_cents' => 80_000],
            ]),
        ]);
    }

    public function store(StoreSubscriptionPriceVersionRequest $request, SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $priceVersion = DB::transaction(function () use ($request, $subscriptionPlan): SubscriptionPriceVersion {
            $validated = $request->validated();
            $priceVersion = $subscriptionPlan->priceVersions()->create([
                'version' => ((int) $subscriptionPlan->priceVersions()->max('version')) + 1,
                'currency' => $validated['currency'],
                'trial_days' => $validated['trial_days'],
                'annual_discount_percent' => $validated['annual_discount_percent'],
            ]);
            $this->replaceTiers($priceVersion, $validated['tiers']);

            return $priceVersion;
        });

        return redirect()->route('platform.subscription-plans.price-versions.preview', [$subscriptionPlan, $priceVersion])
            ->with('status', __('app.price_version_created'));
    }

    public function edit(SubscriptionPlan $subscriptionPlan, SubscriptionPriceVersion $priceVersion): View
    {
        $this->ensureBelongsToPlan($subscriptionPlan, $priceVersion);
        abort_unless($priceVersion->status === SubscriptionPriceStatus::Draft, 409);

        return view('platform.subscription-price-versions.edit', [
            'plan' => $subscriptionPlan,
            'priceVersion' => $priceVersion,
            'tiers' => $priceVersion->tiers()->get(),
        ]);
    }

    public function update(
        UpdateSubscriptionPriceVersionRequest $request,
        SubscriptionPlan $subscriptionPlan,
        SubscriptionPriceVersion $priceVersion,
    ): RedirectResponse {
        $this->ensureBelongsToPlan($subscriptionPlan, $priceVersion);
        abort_unless($priceVersion->status === SubscriptionPriceStatus::Draft, 409);

        DB::transaction(function () use ($request, $priceVersion): void {
            $validated = $request->validated();
            $priceVersion->update([
                'currency' => $validated['currency'],
                'trial_days' => $validated['trial_days'],
                'annual_discount_percent' => $validated['annual_discount_percent'],
            ]);
            $priceVersion->tiers()->delete();
            $this->replaceTiers($priceVersion, $validated['tiers']);
        });

        return redirect()->route('platform.subscription-plans.price-versions.preview', [$subscriptionPlan, $priceVersion])
            ->with('status', __('app.price_version_updated'));
    }

    public function preview(
        SubscriptionPlan $subscriptionPlan,
        SubscriptionPriceVersion $priceVersion,
        SubscriptionPricingCalculator $pricing,
    ): View {
        $this->ensureBelongsToPlan($subscriptionPlan, $priceVersion);
        $priceVersion->load('tiers');

        return view('platform.subscription-price-versions.preview', [
            'plan' => $subscriptionPlan,
            'priceVersion' => $priceVersion,
            'quotes' => collect([1, 2, 3, 6])->mapWithKeys(fn (int $locations): array => [
                $locations => [
                    'monthly' => $pricing->calculate($priceVersion, $locations, SubscriptionBillingInterval::Monthly),
                    'annual' => $pricing->calculate($priceVersion, $locations, SubscriptionBillingInterval::Annual),
                ],
            ]),
        ]);
    }

    public function schedule(Request $request, SubscriptionPlan $subscriptionPlan, SubscriptionPriceVersion $priceVersion): RedirectResponse
    {
        $this->ensureBelongsToPlan($subscriptionPlan, $priceVersion);
        $validated = $request->validate([
            'effective_at' => ['required', 'date', 'after:now'],
        ]);

        return $this->transition($subscriptionPlan, $priceVersion, fn () => $priceVersion->schedule(Carbon::parse($validated['effective_at'])), __('app.price_version_scheduled'));
    }

    public function publish(SubscriptionPlan $subscriptionPlan, SubscriptionPriceVersion $priceVersion): RedirectResponse
    {
        $this->ensureBelongsToPlan($subscriptionPlan, $priceVersion);

        return $this->transition($subscriptionPlan, $priceVersion, fn () => $priceVersion->publish(), __('app.price_version_published'));
    }

    public function retire(SubscriptionPlan $subscriptionPlan, SubscriptionPriceVersion $priceVersion): RedirectResponse
    {
        $this->ensureBelongsToPlan($subscriptionPlan, $priceVersion);

        return $this->transition($subscriptionPlan, $priceVersion, fn () => $priceVersion->retire(), __('app.price_version_retired'));
    }

    public function destroy(SubscriptionPlan $subscriptionPlan, SubscriptionPriceVersion $priceVersion): RedirectResponse
    {
        $this->ensureBelongsToPlan($subscriptionPlan, $priceVersion);

        try {
            $priceVersion->delete();
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['price_version' => $exception->getMessage()]);
        }

        return redirect()->route('platform.subscription-plans.price-versions.index', $subscriptionPlan)
            ->with('status', __('app.price_version_deleted'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function replaceTiers(SubscriptionPriceVersion $priceVersion, array $tiers): void
    {
        collect($tiers)
            ->sortBy('starts_at_location')
            ->each(fn (array $tier) => $priceVersion->tiers()->create([
                'starts_at_location' => $tier['starts_at_location'],
                'ends_at_location' => filled($tier['ends_at_location'] ?? null) ? $tier['ends_at_location'] : null,
                'unit_price_cents' => PaymentAmounts::decimalToCents($tier['unit_price_uah']),
            ]));
    }

    private function ensureBelongsToPlan(SubscriptionPlan $plan, SubscriptionPriceVersion $priceVersion): void
    {
        abort_unless($priceVersion->subscription_plan_id === $plan->id, 404);
    }

    private function transition(
        SubscriptionPlan $plan,
        SubscriptionPriceVersion $priceVersion,
        callable $transition,
        string $message,
    ): RedirectResponse {
        try {
            $transition();
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['price_version' => $exception->getMessage()]);
        }

        return redirect()->route('platform.subscription-plans.price-versions.preview', [$plan, $priceVersion])
            ->with('status', $message);
    }
}
