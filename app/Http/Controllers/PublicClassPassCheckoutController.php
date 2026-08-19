<?php

namespace App\Http\Controllers;

use App\Actions\Payments\CompleteFreeCustomerPurchase;
use App\Actions\Payments\CreateCustomerPurchase;
use App\Actions\Payments\StartCustomerPurchasePayment;
use App\Actions\ResolvePublicClassPassPurchaseContext;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\IntegrationProvider;
use App\Http\Requests\StartPublicClassPassCheckoutRequest;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\PublicClassPassCheckoutContext;
use App\Support\TrialClassPassEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublicClassPassCheckoutController extends Controller
{
    public function __construct(
        private readonly ResolvePublicClassPassPurchaseContext $resolveContext,
        private readonly PublicClassPassCheckoutContext $checkoutContext,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly CustomerAuthAvailability $authAvailability,
        private readonly TrialClassPassEligibility $trialEligibility,
    ) {}

    public function show(
        Request $request,
        string $accountSlug,
        string $locationSlug,
        string $classPassPlanSlug,
    ): Response {
        [$account, $location, $classPassPlan] = $this->context($accountSlug, $locationSlug, $classPassPlanSlug);
        $this->checkoutContext->remember($account, $location, $classPassPlan);
        $request->session()->put('url.intended', $this->checkoutContext->urlFor($account));
        $customer = $this->customerForAccount($account);
        $purchase = $this->checkoutContext->purchaseFor($account, $location, $classPassPlan, $customer);
        $stage = $this->stage($account, $customer, $purchase);

        return response()
            ->view('public.class-pass-checkout', [
                'account' => $account,
                'location' => $location,
                'classPassPlan' => $classPassPlan,
                'customer' => $customer,
                'purchase' => $purchase,
                'stage' => $stage,
                'methods' => $this->authAvailability->methodsFor($account),
                'otpPhone' => $this->sessionString('customer_otp_phone_'.$account->id),
                'googlePhone' => $this->sessionString('customer_google_phone_'.$account->id),
                'profilePhoneMerge' => $this->profilePhoneMergeState($account),
                'paymentSettings' => $stage === 'payment' ? $this->gateways->availableSettingsFor($account) : collect(),
                'trialIsAvailable' => $this->trialIsAvailable($account, $customer, $classPassPlan),
                'statusUrl' => $purchase ? route('public.class-pass-plans.checkout.status', [
                    $account->slug,
                    $location->slug,
                    $classPassPlan->slug,
                    $purchase,
                ]) : null,
            ])
            ->withHeaders($this->privateHeaders());
    }

    public function store(
        StartPublicClassPassCheckoutRequest $request,
        string $accountSlug,
        string $locationSlug,
        string $classPassPlanSlug,
        CreateCustomerPurchase $createCustomerPurchase,
        StartCustomerPurchasePayment $startCustomerPurchasePayment,
        CompleteFreeCustomerPurchase $completeFreeCustomerPurchase,
    ): Response|RedirectResponse {
        [$account, $location, $classPassPlan] = $this->context($accountSlug, $locationSlug, $classPassPlanSlug);
        $this->checkoutContext->remember($account, $location, $classPassPlan);
        $customer = $this->customerForAccount($account);

        if (! $customer || ! $customer->profileIsComplete()) {
            return redirect()->to((string) $this->checkoutContext->urlFor($account));
        }

        if ($this->checkoutContext->purchaseFor($account, $location, $classPassPlan, $customer)) {
            return redirect()->to((string) $this->checkoutContext->urlFor($account));
        }

        $this->trialEligibility->assertAvailable(
            $account,
            $customer,
            $classPassPlan,
            TrialClassPassEligibility::SourceOnlinePayment,
        );

        if ($classPassPlan->price_cents === 0) {
            $purchase = $completeFreeCustomerPurchase->execute($account, $customer, $classPassPlan, $location);
            $this->checkoutContext->rememberPurchase($account, $location, $classPassPlan, $purchase);

            return redirect()->to((string) $this->checkoutContext->urlFor($account));
        }

        $providerValue = $request->validated('provider');

        if (! is_string($providerValue) || $providerValue === '') {
            throw ValidationException::withMessages(['provider' => __('validation.required', ['attribute' => __('app.payment_method')])]);
        }

        $provider = IntegrationProvider::from($providerValue);
        $setting = $this->gateways->availableSettingsFor($account)
            ->first(fn ($setting): bool => $setting->provider === $provider);

        if (! $setting) {
            throw ValidationException::withMessages(['provider' => __('app.payment_provider_unavailable')]);
        }

        $purchase = null;

        try {
            $purchase = $createCustomerPurchase->execute($account, $customer, $classPassPlan, $provider, $location);
            $this->checkoutContext->rememberPurchase($account, $location, $classPassPlan, $purchase);
            $checkout = $startCustomerPurchasePayment->execute(
                $purchase,
                $setting,
                URL::temporarySignedRoute('public.class-pass-plans.checkout.return', now()->addHours(2), [
                    $account->slug,
                    $location->slug,
                    $classPassPlan->slug,
                    $purchase,
                ]),
            );
        } catch (Throwable $exception) {
            if ($purchase) {
                $purchase->forceFill([
                    'status' => CustomerPurchaseStatus::PaymentFailed,
                    'failure_reason' => $exception->getMessage(),
                    'failed_at' => now(),
                ])->save();
            }

            throw ValidationException::withMessages(['provider' => __('app.payment_start_failed')]);
        }

        if ($checkout->isRedirect()) {
            return redirect()->away($checkout->url);
        }

        return response()
            ->view('payments.redirect-form', compact('account', 'purchase', 'checkout'))
            ->withHeaders($this->privateHeaders());
    }

    public function paymentReturn(
        string $accountSlug,
        string $locationSlug,
        string $classPassPlanSlug,
        int $customerPurchase,
    ): RedirectResponse {
        [$account, $location, $classPassPlan] = $this->context($accountSlug, $locationSlug, $classPassPlanSlug);
        $purchase = $this->scopedPurchase($account, $location, $classPassPlan, $customerPurchase);
        $this->checkoutContext->rememberPurchase($account, $location, $classPassPlan, $purchase);

        return redirect()->to((string) $this->checkoutContext->urlFor($account));
    }

    public function status(
        string $accountSlug,
        string $locationSlug,
        string $classPassPlanSlug,
        int $customerPurchase,
    ): JsonResponse {
        [$account, $location, $classPassPlan] = $this->context($accountSlug, $locationSlug, $classPassPlanSlug);
        $customer = $this->customerForAccount($account);
        abort_unless($customer, 404);
        $purchase = $this->scopedPurchase($account, $location, $classPassPlan, $customerPurchase, $customer)
            ->loadMissing('customerClassPass');

        return response()->json([
            'status' => $purchase->status->value,
            'terminal' => $purchase->status->isFinal(),
            'paid' => $purchase->isPaid(),
            'class_pass_ready' => $purchase->customerClassPass !== null,
        ])->withHeaders($this->privateHeaders());
    }

    public function retry(
        string $accountSlug,
        string $locationSlug,
        string $classPassPlanSlug,
    ): RedirectResponse {
        [$account, $location, $classPassPlan] = $this->context($accountSlug, $locationSlug, $classPassPlanSlug);
        $customer = $this->customerForAccount($account);
        $purchase = $this->checkoutContext->purchaseFor($account, $location, $classPassPlan, $customer);
        abort_unless($purchase && $purchase->status->isFinal() && ! $purchase->isPaid(), 404);
        $this->checkoutContext->forgetPurchase();

        return redirect()->to((string) $this->checkoutContext->urlFor($account));
    }

    /**
     * @return array{0: Account, 1: Location, 2: ClassPassPlan}
     */
    private function context(string $accountSlug, string $locationSlug, string $classPassPlanSlug): array
    {
        $context = $this->resolveContext->execute($accountSlug, $locationSlug, $classPassPlanSlug);
        $this->setAccountLocale($context[0]);

        return $context;
    }

    private function stage(Account $account, ?Customer $customer, ?CustomerPurchase $purchase): string
    {
        if (! $customer) {
            return is_array(session('customer_google_pending_'.$account->id)) ? 'google_phone' : 'login';
        }

        if (! $customer->profileIsComplete()) {
            return 'profile';
        }

        return $purchase ? 'confirmation' : 'payment';
    }

    private function customerForAccount(Account $account): ?Customer
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->account_id === $account->id ? $customer : null;
    }

    private function scopedPurchase(
        Account $account,
        Location $location,
        ClassPassPlan $classPassPlan,
        int $purchaseId,
        ?Customer $customer = null,
    ): CustomerPurchase {
        return CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($location)
            ->whereBelongsTo($classPassPlan)
            ->when($customer, fn ($query) => $query->whereBelongsTo($customer))
            ->findOrFail($purchaseId);
    }

    private function trialIsAvailable(Account $account, ?Customer $customer, ClassPassPlan $classPassPlan): bool
    {
        return ! $classPassPlan->is_trial
            || ($customer && $this->trialEligibility->evaluate(
                $account,
                $customer,
                TrialClassPassEligibility::SourceOnlinePayment,
            )['status'] === 'eligible');
    }

    /**
     * @return array{phone: string, challenge_active: bool}|null
     */
    private function profilePhoneMergeState(Account $account): ?array
    {
        $phone = $this->sessionString('customer_profile_phone_'.$account->id);

        return $phone ? [
            'phone' => $phone,
            'challenge_active' => (bool) session('customer_profile_phone_challenge_'.$account->id),
        ] : null;
    }

    private function sessionString(string $key): ?string
    {
        $value = session($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }

    /**
     * @return array<string, string>
     */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
