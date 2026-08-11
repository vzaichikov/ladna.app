<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\CreateFestivalEditionPurchase;
use App\Actions\Festivals\StartFestivalEditionPurchasePayment;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Http\Requests\FestivalEditionPurchaseRequest;
use App\Models\Account;
use App\Models\FestivalTariffPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class FestivalEditionPurchaseController extends Controller
{
    public function store(
        FestivalEditionPurchaseRequest $request,
        Account $account,
        CreateFestivalEditionPurchase $create,
        StartFestivalEditionPurchasePayment $startPayment,
    ): RedirectResponse {
        $package = FestivalTariffPackage::query()->findOrFail($request->integer('festival_tariff_package_id'));
        $purchase = $create->execute($account, $package, $request->user(), (string) $request->validated('idempotency_key'));

        if ($purchase->status === FestivalEditionPurchaseStatus::Available) {
            return redirect()->route('dashboard.accounts.festivals.create', [$account, 'purchase' => $purchase->id])
                ->with('status', __('app.festival_entitlement_available'));
        }

        try {
            $checkoutUrl = $startPayment->execute($purchase, route('dashboard.accounts.festivals.index', [
                'account' => $account,
                'tab' => 'payments',
            ]));
        } catch (Throwable $exception) {
            $purchase->forceFill([
                'status' => FestivalEditionPurchaseStatus::PaymentFailed,
                'failure_reason' => $exception->getMessage(),
                'failed_at' => now(),
            ])->save();

            throw ValidationException::withMessages(['package' => __('app.payment_start_failed')]);
        }

        if ($purchase->refresh()->status === FestivalEditionPurchaseStatus::Available) {
            return redirect()->route('dashboard.accounts.festivals.create', [$account, 'purchase' => $purchase->id])
                ->with('status', __('app.festival_entitlement_available'));
        }

        return $checkoutUrl
            ? redirect()->away($checkoutUrl)
            : redirect()->route('dashboard.accounts.festivals.index', [
                'account' => $account,
                'tab' => 'payments',
            ])->with('status', __('app.festival_payment_pending'));
    }
}
