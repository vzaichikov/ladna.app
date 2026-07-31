<?php

namespace App\Http\Controllers;

use App\Enums\SmsSendingMode;
use App\Enums\SmsTopUpKind;
use App\Http\Requests\StoreSmsTopUpRequest;
use App\Models\Account;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\Sms\ChargeSmsTopUpPayment;
use App\Support\Sms\CreateSmsTopUpPayment;
use App\Support\Sms\SmsServiceSettings;
use App\Support\Sms\StartSmsPaymentMethodVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountSmsTopUpController extends Controller
{
    public function store(
        StoreSmsTopUpRequest $request,
        Account $account,
        SmsServiceSettings $settings,
        MonopaySaasBilling $billing,
        CreateSmsTopUpPayment $createPayment,
        ChargeSmsTopUpPayment $chargePayment,
        StartSmsPaymentMethodVerification $startVerification,
    ): RedirectResponse {
        $this->authorize('view', $account);
        $account->loadMissing(['customerAuthSetting', 'subscription.paymentMethod', 'subscription.plan']);

        if (
            $account->isReadOnlyDemo()
            ||
            ! $settings->enabled()
            || $account->customerAuthSetting?->sms_sending_mode !== SmsSendingMode::LadnaService
            || $account->subscription?->plan?->sms_segment_price_cents === null
            || (int) $account->subscription->plan->sms_segment_price_cents === 0
        ) {
            throw ValidationException::withMessages(['amount_cents' => __('app.sms_top_up_unavailable')]);
        }

        $setting = $billing->platformSetting();

        if (! $setting) {
            throw ValidationException::withMessages(['amount_cents' => __('app.payment_provider_unavailable')]);
        }

        try {
            if (! $account->subscription?->paymentMethod?->isActive()) {
                $checkout = $startVerification->execute(
                    $account,
                    $request->amountCents(),
                    $setting,
                    route('dashboard.accounts.sms-account.show', $account),
                );

                return redirect()->away($checkout->url);
            }

            $payment = $createPayment->execute(
                $account,
                $request->amountCents(),
                SmsTopUpKind::Manual,
            );
            $redirectUrl = $chargePayment->execute(
                $payment,
                $setting,
                route('dashboard.accounts.sms-account.show', $account),
                true,
            );

            return $redirectUrl
                ? redirect()->away($redirectUrl)
                : redirect()->route('dashboard.accounts.sms-account.show', $account)
                    ->with('status', __('app.sms_top_up_processing'));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['amount_cents' => __('app.payment_start_failed')]);
        }
    }
}
