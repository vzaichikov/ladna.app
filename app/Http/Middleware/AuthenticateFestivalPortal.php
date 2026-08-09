<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\FestivalPortalUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFestivalPortal
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');

        if (! $portalUser instanceof FestivalPortalUser) {
            return redirect()->guest(route('festival.login', ['accountSlug' => $request->route('accountSlug')]));
        }

        abort_unless($account instanceof Account && $portalUser->account_id === $account->id, 404);

        return $next($request);
    }
}
