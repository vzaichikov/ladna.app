<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnrollAccountBillingRequest;
use App\Models\Account;
use App\Models\SubscriptionPriceVersion;
use App\Support\SaasBilling\EnrollAccountInBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use LogicException;

class AccountBillingEnrollmentController extends Controller
{
    public function __invoke(
        EnrollAccountBillingRequest $request,
        Account $account,
        EnrollAccountInBilling $enrollAccount,
    ): RedirectResponse {
        $priceVersion = SubscriptionPriceVersion::query()
            ->with('plan')
            ->findOrFail($request->integer('subscription_price_version_id'));

        try {
            $enrollAccount->execute($account, $priceVersion);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'billing' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('platform.accounts.show', $account)
            ->with('status', __('app.billing_v2_trial_started'));
    }
}
