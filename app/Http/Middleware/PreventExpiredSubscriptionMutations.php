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
        if ($request->user()?->isPlatformAdmin()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $account = $this->routeAccount($request);

        if (
            $account
            && $this->subscriptionAccess->requiresInitialDemoPayment($account)
            && ! $this->isPrePaymentRoute($routeName)
        ) {
            if ($request->isMethodSafe()) {
                return redirect()
                    ->route('dashboard.accounts.tariff-payments.show', $account)
                    ->withErrors(['subscription' => __('app.demo_payment_required_readonly')]);
            }

            if ($request->expectsJson()) {
                abort(Response::HTTP_LOCKED, 'demo_payment_required');
            }

            return redirect()
                ->back()
                ->withErrors(['subscription' => __('app.demo_payment_required_readonly')]);
        }

        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if ($this->isPrePaymentRoute($routeName)) {
            return $next($request);
        }

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

    private function routeAccount(Request $request): ?Account
    {
        $routeAccount = $request->route('account');

        return $routeAccount instanceof Account ? $routeAccount : null;
    }

    private function isPrePaymentRoute(?string $routeName): bool
    {
        if (! is_string($routeName)) {
            return false;
        }

        return str_starts_with($routeName, 'dashboard.accounts.tariff-payments.')
            || str_starts_with($routeName, 'dashboard.accounts.owner-profile.');
    }
}
