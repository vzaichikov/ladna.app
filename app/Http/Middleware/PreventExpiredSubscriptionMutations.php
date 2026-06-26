<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventExpiredSubscriptionMutations
{
    public function __construct(private readonly AccountSubscriptionAccess $subscriptionAccess) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if ($request->user()?->isPlatformAdmin()) {
            return $next($request);
        }

        if ($this->isBillingRoute($request->route()?->getName())) {
            return $next($request);
        }

        $routeAccount = $request->route('account');
        $account = $routeAccount instanceof Account ? $routeAccount : null;

        if (! $account || $this->subscriptionAccess->canEditStudio($account)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_LOCKED, 'subscription_expired_readonly');
        }

        return redirect()
            ->back()
            ->withErrors(['subscription' => __('app.subscription_expired_readonly')]);
    }

    private function isBillingRoute(?string $routeName): bool
    {
        return is_string($routeName)
            && str_starts_with($routeName, 'dashboard.accounts.tariff-payments.');
    }
}
