<?php

namespace App\Http\Controllers;

use App\Actions\Payments\CreateCustomerPurchase;
use App\Actions\Payments\StartCustomerPurchasePayment;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\IntegrationProvider;
use App\Http\Requests\StartCustomerPurchaseRequest;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\Location;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\ScheduleKindRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PublicClassPassPurchaseController extends Controller
{
    public function show(
        Request $request,
        string $accountSlug,
        string $locationSlug,
        string $classPassPlanSlug,
        PaymentGatewayRegistry $gateways,
    ): View|RedirectResponse {
        [$account, $location, $classPassPlan] = $this->purchaseContext($accountSlug, $locationSlug, $classPassPlanSlug);
        $redirect = $this->redirectIfCustomerCannotCheckout($request, $account);

        if ($redirect) {
            return $redirect;
        }

        return view('public.class-pass-buy', [
            'account' => $account,
            'location' => $location,
            'classPassPlan' => $classPassPlan,
            'paymentSettings' => $gateways->availableSettingsFor($account),
        ]);
    }

    public function store(
        StartCustomerPurchaseRequest $request,
        string $accountSlug,
        string $locationSlug,
        string $classPassPlanSlug,
        PaymentGatewayRegistry $gateways,
        CreateCustomerPurchase $createCustomerPurchase,
        StartCustomerPurchasePayment $startCustomerPurchasePayment,
    ): View|RedirectResponse {
        [$account, $location, $classPassPlan] = $this->purchaseContext($accountSlug, $locationSlug, $classPassPlanSlug);
        $redirect = $this->redirectIfCustomerCannotCheckout($request, $account);

        if ($redirect) {
            return $redirect;
        }

        $provider = IntegrationProvider::from($request->validated('provider'));
        $setting = $gateways->availableSettingsFor($account)
            ->first(fn ($setting): bool => $setting->provider === $provider);

        if (! $setting) {
            throw ValidationException::withMessages([
                'provider' => __('app.payment_provider_unavailable'),
            ]);
        }

        $purchase = null;

        try {
            $purchase = $createCustomerPurchase->execute(
                $account,
                $this->customerForAccount($account),
                $classPassPlan,
                $provider,
                $location,
            );
            $checkout = $startCustomerPurchasePayment->execute($purchase, $setting);
        } catch (Throwable $exception) {
            if ($purchase) {
                $purchase->forceFill([
                    'status' => CustomerPurchaseStatus::PaymentFailed,
                    'failure_reason' => $exception->getMessage(),
                    'failed_at' => now(),
                ])->save();
            }

            throw ValidationException::withMessages([
                'provider' => __('app.payment_start_failed'),
            ]);
        }

        if ($checkout->isRedirect()) {
            return redirect()->away($checkout->url);
        }

        return view('payments.redirect-form', [
            'account' => $account,
            'purchase' => $purchase,
            'checkout' => $checkout,
        ]);
    }

    /**
     * @return array{0: Account, 1: Location, 2: ClassPassPlan}
     */
    private function purchaseContext(string $accountSlug, string $locationSlug, string $classPassPlanSlug): array
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $this->setAccountLocale($account);

        $location = $account->locations()
            ->where('slug', $locationSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $classPassPlan = $account->classPassPlans()
            ->active()
            ->where('slug', $classPassPlanSlug)
            ->with(['classTypes', 'trainerTypes', 'rooms.location'])
            ->firstOrFail();

        abort_unless($account->hasScheduleKindEnabled($classPassPlan->schedule_kind), 404);
        abort_unless(ScheduleKindRegistry::hasCapability($classPassPlan->schedule_kind, 'class_pass_eligible'), 404);
        abort_unless($this->planIsVisibleForLocation($classPassPlan, $location), 404);

        return [$account, $location, $classPassPlan];
    }

    private function planIsVisibleForLocation(ClassPassPlan $classPassPlan, Location $location): bool
    {
        return $classPassPlan->rooms->isEmpty()
            || $classPassPlan->rooms->contains(fn ($room): bool => $room->location_id === $location->id);
    }

    private function redirectIfCustomerCannotCheckout(Request $request, Account $account): ?RedirectResponse
    {
        $intendedUrl = $request->isMethod('GET')
            ? $request->fullUrl()
            : route('public.class-pass-plans.buy', [
                $account->slug,
                $request->route('locationSlug'),
                $request->route('classPassPlanSlug'),
            ]);
        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer || $customer->account_id !== $account->id) {
            session()->put('url.intended', $intendedUrl);

            return redirect()->route('customer.studio.login', $account->slug);
        }

        if (! $customer->profileIsComplete()) {
            session()->put('url.intended', $intendedUrl);

            return redirect()->route('customer.profile.complete', $account->slug);
        }

        return null;
    }

    private function customerForAccount(Account $account): Customer
    {
        $customer = Auth::guard('customer')->user();

        abort_unless($customer instanceof Customer && $customer->account_id === $account->id, 404);

        return $customer;
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }
}
