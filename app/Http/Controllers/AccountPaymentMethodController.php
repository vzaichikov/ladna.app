<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeAccountPaymentMethodRequest;
use App\Models\Account;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\ReplaceAccountPaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class AccountPaymentMethodController extends Controller
{
    public function __invoke(
        ChangeAccountPaymentMethodRequest $request,
        Account $account,
        MonopaySaasBilling $billing,
        ReplaceAccountPaymentMethod $replacePaymentMethod,
    ): RedirectResponse {
        $setting = $billing->platformSetting();

        if (! $setting) {
            throw ValidationException::withMessages(['payment_method' => __('app.payment_provider_unavailable')]);
        }

        try {
            $checkout = $replacePaymentMethod->execute(
                $account,
                $setting,
                route($request->returnRouteName(), $account),
            );
        } catch (LogicException $exception) {
            $message = $exception->getMessage() === ReplaceAccountPaymentMethod::IN_PROGRESS
                ? __('app.payment_method_change_in_progress')
                : __('app.payment_method_change_failed');

            throw ValidationException::withMessages(['payment_method' => $message]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['payment_method' => __('app.payment_method_change_failed')]);
        }

        return redirect()->away($checkout->url);
    }
}
