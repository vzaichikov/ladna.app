<?php

namespace App\Http\Controllers;

use App\Actions\ResolvePublicClassPassPurchaseContext;
use Illuminate\Http\RedirectResponse;

class PublicClassPassPurchaseController extends Controller
{
    public function show(
        string $accountSlug,
        string $locationSlug,
        string $classPassPlanSlug,
        ResolvePublicClassPassPurchaseContext $resolveContext,
    ): RedirectResponse {
        [$account, $location, $classPassPlan] = $resolveContext->execute($accountSlug, $locationSlug, $classPassPlanSlug);

        return redirect()->route('public.class-pass-plans.checkout', [
            $account->slug,
            $location->slug,
            $classPassPlan->slug,
        ]);
    }

    public function store(
        string $accountSlug,
        string $locationSlug,
        string $classPassPlanSlug,
        ResolvePublicClassPassPurchaseContext $resolveContext,
    ): RedirectResponse {
        return $this->show($accountSlug, $locationSlug, $classPassPlanSlug, $resolveContext)
            ->setStatusCode(303);
    }
}
