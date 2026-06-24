<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerIsAuthenticated
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('customer')->check()) {
            if ($request->isMethod('GET')) {
                session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('customer.studio.login', $request->route('accountSlug'));
        }

        return $next($request);
    }
}
