<?php

namespace App\Http\Middleware;

use App\Support\CustomerAuth\CustomerRememberTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCustomerRememberToken
{
    public function __construct(private CustomerRememberTokenService $rememberTokens) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->rememberTokens->authenticateFromCookie($request);

        return $next($request);
    }
}
