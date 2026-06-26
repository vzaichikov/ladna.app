<?php

namespace App\Http\Controllers;

use App\Enums\AccountSignupStatus;
use App\Enums\AccountSubscriptionPaymentStatus;
use App\Http\Requests\StartDemoSignupRequest;
use App\Models\AccountSignupRequest;
use App\Support\Payments\PaymentGatewayException;
use App\Support\SaasBilling\CreateDemoSignup;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\SaasBillingPlans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicDemoSignupController extends Controller
{
    public function create(SaasBillingPlans $plans): View
    {
        return view('demo-signup.create', [
            'demoPlan' => $plans->demoPlan(),
            'standardPlan' => $plans->standardPlan(),
        ]);
    }

    public function store(
        StartDemoSignupRequest $request,
        SaasBillingPlans $plans,
        CreateDemoSignup $createDemoSignup,
        MonopaySaasBilling $billing,
    ): RedirectResponse {
        $setting = $billing->platformSetting();

        if (! $setting) {
            throw ValidationException::withMessages([
                'provider' => __('app.payment_provider_unavailable'),
            ]);
        }

        [$signup, $payment] = $createDemoSignup->execute($request->validated(), $plans->demoPlan());

        try {
            $checkout = $billing->startOneTimePayment(
                $payment,
                $setting,
                route('demo.return', $signup),
            );
        } catch (PaymentGatewayException) {
            $signup->forceFill([
                'status' => AccountSignupStatus::PaymentFailed,
                'failure_reason' => __('app.payment_start_failed'),
            ])->save();

            $payment->forceFill([
                'status' => AccountSubscriptionPaymentStatus::PaymentFailed,
                'failure_reason' => __('app.payment_start_failed'),
                'failed_at' => now(),
            ])->save();

            throw ValidationException::withMessages([
                'provider' => __('app.payment_start_failed'),
            ]);
        }

        $payload = $checkout->gatewayPayload;
        $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];

        $payment->forceFill([
            'gateway_invoice_id' => is_string($response['invoiceId'] ?? null) ? $response['invoiceId'] : null,
            'gateway_status' => is_string($response['status'] ?? null) ? $response['status'] : null,
            'gateway_checkout_payload' => $payload,
        ])->save();

        $signup->forceFill([
            'gateway_invoice_id' => $payment->gateway_invoice_id,
            'gateway_status' => $payment->gateway_status,
            'gateway_checkout_payload' => $payload,
        ])->save();

        return redirect()->away($checkout->url);
    }

    public function returned(AccountSignupRequest $accountSignupRequest): RedirectResponse
    {
        $accountSignupRequest->loadMissing('account');

        if ($accountSignupRequest->account) {
            return redirect()
                ->route('login')
                ->with('status', __('app.demo_signup_payment_completed'));
        }

        return redirect()
            ->route('demo.signup.create')
            ->with('status', __('app.payment_processing'));
    }
}
