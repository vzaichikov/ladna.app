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

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => __('app.subscription_expired_public_api_message'),
                'code' => 'subscription_expired',
                'support_url' => $supportUrl,
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        return response()->view('public.subscription-expired', [
            'account' => $account,
            'supportUrl' => $supportUrl,
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
