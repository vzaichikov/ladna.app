<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Support\Http\AccountFromRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFestivalsEnabled
{
    public function __construct(private readonly AccountFromRequest $accountFromRequest) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $account = $this->accountFromRequest->resolve($request);

        abort_unless($account instanceof Account && $account->enable_festivals, 404);
        $request->attributes->set('festivalAccount', $account);

        return $next($request);
    }
}
