<?php

namespace App\Http\Controllers;

use App\Enums\AccountSignupStatus;
use App\Enums\AccountSubscriptionPaymentStatus;
use App\Http\Requests\StartDemoSignupRequest;
use App\Models\AccountSignupRequest;
use App\Support\SaasBilling\CreateDemoSignup;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\SaasBillingPlans;
use App\Support\SaasBilling\StartAccountSubscriptionPaymentCheckout;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class PublicDemoSignupController extends Controller
{
    public function create(SaasBillingPlans $plans): View|RedirectResponse
    {
        try {
            $demoPlan = $plans->demoPlan();
        } catch (ModelNotFoundException) {
            return redirect()->to(route('home', [], false).'#pricing');
        }

        return view('demo-signup.create', [
            'demoPlan' => $demoPlan,
            'standardPlan' => $plans->standardPlan(),
        ]);
    }

    public function store(
        StartDemoSignupRequest $request,
        SaasBillingPlans $plans,
        CreateDemoSignup $createDemoSignup,
        MonopaySaasBilling $billing,
        StartAccountSubscriptionPaymentCheckout $startCheckout,
    ): RedirectResponse {
        [$signup, $payment, $account, $owner] = $createDemoSignup->execute($request->validated(), $plans->demoPlan());

        Auth::login($owner);
        $request->session()->regenerate();

        $setting = $billing->platformSetting();

        if (! $setting) {
            $signup->forceFill([
                'status' => AccountSignupStatus::PaymentFailed,
                'failure_reason' => __('app.payment_provider_unavailable'),
            ])->save();

            $payment->forceFill([
                'status' => AccountSubscriptionPaymentStatus::PaymentFailed,
                'failure_reason' => __('app.payment_provider_unavailable'),
                'failed_at' => now(),
            ])->save();

            return redirect()
                ->route('dashboard.accounts.tariff-payments.show', $account)
                ->withErrors(['provider' => __('app.payment_provider_unavailable')]);
        }

        try {
            $startCheckout->execute($payment, $setting, route('demo.return', $signup));
        } catch (Throwable) {
            $signup->forceFill([
                'status' => AccountSignupStatus::PaymentFailed,
                'failure_reason' => __('app.payment_start_failed'),
            ])->save();

            $payment->forceFill([
                'status' => AccountSubscriptionPaymentStatus::PaymentFailed,
                'failure_reason' => __('app.payment_start_failed'),
                'failed_at' => now(),
            ])->save();

            return redirect()
                ->route('dashboard.accounts.tariff-payments.show', $account)
                ->withErrors(['provider' => __('app.payment_start_failed')]);
        }

        return redirect()
            ->route('dashboard.accounts.tariff-payments.show', $account)
            ->with('status', __('app.demo_signup_account_created'));
    }

    public function returned(Request $request, AccountSignupRequest $accountSignupRequest): RedirectResponse
    {
        $accountSignupRequest->loadMissing('account');

        if ($accountSignupRequest->account) {
            $status = $accountSignupRequest->status === AccountSignupStatus::AccountCreated
                ? __('app.demo_signup_payment_completed')
                : __('app.payment_processing');

            if ($request->user() && $accountSignupRequest->account->isAccessibleBy($request->user())) {
                return redirect()
                    ->route('dashboard.accounts.tariff-payments.show', $accountSignupRequest->account)
                    ->with('status', $status);
            }

            return redirect()
                ->route('login')
                ->with('status', $status);
        }

        return redirect()
            ->route('demo.signup.create')
            ->with('status', __('app.payment_processing'));
    }
}
