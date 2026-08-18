<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignAccountPromoTariffRequest;
use App\Models\Account;
use App\Models\SubscriptionPlan;
use App\Support\SaasBilling\AssignAccountPromoTariff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use LogicException;

class AccountPromoTariffController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        AssignAccountPromoTariffRequest $request,
        Account $account,
        AssignAccountPromoTariff $assignPromoTariff,
    ): RedirectResponse {
        $promoPlan = SubscriptionPlan::query()->findOrFail($request->integer('subscription_plan_id'));

        try {
            $assignPromoTariff->execute($account, $promoPlan);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'promo_tariff' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('platform.accounts.show', $account)
            ->with('status', __('app.promo_tariff_granted'));
    }
}
