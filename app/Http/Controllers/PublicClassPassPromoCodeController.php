<?php

namespace App\Http\Controllers;

use App\Actions\ResolvePublicClassPassPurchaseContext;
use App\Http\Requests\QuoteStudioPromoCodeRequest;
use App\Models\Customer;
use App\Support\MoneyFormatter;
use App\Support\Promotions\StudioPromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PublicClassPassPromoCodeController extends Controller
{
    public function __invoke(
        QuoteStudioPromoCodeRequest $request,
        string $accountSlug,
        string $locationSlug,
        string $classPassPlanSlug,
        ResolvePublicClassPassPurchaseContext $resolveContext,
        StudioPromoCodeService $promoCodes,
    ): JsonResponse {
        [$account, , $classPassPlan] = $resolveContext->execute($accountSlug, $locationSlug, $classPassPlanSlug);
        $customer = Auth::guard('customer')->user();
        abort_unless($customer instanceof Customer && $customer->account_id === $account->id && $customer->profileIsComplete(), 404);

        $result = $promoCodes->quote($account, $classPassPlan, $customer, $request->validated('promo_code'));
        $quote = $result['quote'];

        return response()->json([
            'code' => $result['promoCode']->code,
            'name' => $result['promoCode']->name,
            'subtotal_cents' => $quote->subtotalCents,
            'eligible_subtotal_cents' => $quote->eligibleSubtotalCents,
            'discount_cents' => $quote->discountCents,
            'total_cents' => $quote->totalCents,
            'currency' => $classPassPlan->currency,
            'subtotal' => MoneyFormatter::format($quote->subtotalCents, $classPassPlan->currency),
            'discount' => MoneyFormatter::format($quote->discountCents, $classPassPlan->currency),
            'total' => MoneyFormatter::format($quote->totalCents, $classPassPlan->currency),
            'requires_payment' => $quote->totalCents > 0,
        ]);
    }
}
