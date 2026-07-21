<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignAccountTariffRequest;
use App\Models\Account;
use App\Models\SubscriptionPriceVersion;
use App\Support\SaasBilling\AssignAccountSubscriptionTariff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use LogicException;

class AccountBillingTariffController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        AssignAccountTariffRequest $request,
        Account $account,
        AssignAccountSubscriptionTariff $assignTariff,
    ): RedirectResponse {
        $priceVersion = SubscriptionPriceVersion::query()
            ->with(['plan', 'tiers'])
            ->findOrFail($request->integer('subscription_price_version_id'));

        try {
            $subscription = $assignTariff->execute($account, $priceVersion);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'tariff' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('platform.accounts.show', $account)
            ->with(
                'status',
                $subscription->pending_subscription_price_version_id
                    ? __('app.billing_tariff_change_scheduled')
                    : __('app.billing_tariff_changed'),
            );
    }
}
