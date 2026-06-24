<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerProfileIsComplete
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();
        $accountSlug = $request->route('accountSlug');

        if ($customer && ! $customer->profileIsComplete() && ! $request->routeIs('customer.profile.*')) {
            if ($request->isMethod('GET')) {
                session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('customer.profile.complete', $accountSlug);
        }

        return $next($request);
    }
}
