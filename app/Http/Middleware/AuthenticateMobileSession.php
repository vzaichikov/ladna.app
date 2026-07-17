<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Models\MobileSession;
use App\Support\Mobile\MobileSessionIssuer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json(['message' => __('app.api_token_missing')], Response::HTTP_UNAUTHORIZED);
        }

        $session = MobileSession::with(['account', 'user', 'customer'])
            ->where('token_hash', app(MobileSessionIssuer::class)->hash($bearerToken))
            ->first();

        if (
            ! $session
            || ! $session->account
            || $session->account->status !== AccountStatus::Active
            || ! $session->isActive()
            || ($session->guard === MobileSession::GuardStaff && ! $session->user)
            || ($session->guard === MobileSession::GuardCustomer && ! $session->customer)
        ) {
            return response()->json(['message' => __('app.api_token_invalid')], Response::HTTP_UNAUTHORIZED);
        }

        if (! $session->account->isReadOnlyDemo()) {
            $session->forceFill(['last_used_at' => now()])->save();
        }
        $request->attributes->set('mobileSession', $session);
        $request->attributes->set('account', $session->account);
        $request->attributes->set('mobileGuard', $session->guard);

        return $next($request);
    }
}
