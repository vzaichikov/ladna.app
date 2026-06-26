<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\SystemSetting;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicSubscriptionIsActive
{
    public function __construct(private readonly AccountSubscriptionAccess $subscriptionAccess) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $account = $this->accountFromRequest($request);

        if (! $account) {
            return $next($request);
        }

        if ($this->subscriptionAccess->canUsePublicFeatures($account)) {
            return $next($request);
        }

        $supportUrl = SystemSetting::stringValue(SystemSetting::SupportUrlKey);
        $requiresInitialDemoPayment = $this->subscriptionAccess->requiresInitialDemoPayment($account);

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => $requiresInitialDemoPayment
                    ? __('app.demo_payment_required_public_api_message')
                    : __('app.subscription_expired_public_api_message'),
                'code' => $requiresInitialDemoPayment ? 'demo_payment_required' : 'subscription_expired',
                'support_url' => $supportUrl,
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        return response()->view('public.subscription-expired', [
            'account' => $account,
            'supportUrl' => $supportUrl,
            'title' => $requiresInitialDemoPayment
                ? __('app.demo_payment_required_public_title')
                : __('app.subscription_expired_public_title'),
            'copy' => $requiresInitialDemoPayment
                ? __('app.demo_payment_required_public_copy')
                : __('app.subscription_expired_public_copy'),
        ], Response::HTTP_PAYMENT_REQUIRED);
    }

    private function accountFromRequest(Request $request): ?Account
    {
        $account = $request->attributes->get('account');

        if ($account instanceof Account) {
            return $account->loadMissing('subscription.plan');
        }

        $accountSlug = $request->route('accountSlug');

        if (! is_string($accountSlug) || $accountSlug === '') {
            return null;
        }

        return Account::active()
            ->with('subscription.plan')
            ->where('slug', $accountSlug)
            ->first();
    }
}
