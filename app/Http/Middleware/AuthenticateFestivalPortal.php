<?php

namespace App\Http\Middleware;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $expectedRole = $request->routeIs('festival.portal.judge.*', 'festival.portal.judging.*', 'festival.portal.battle-votes.*')
            ? FestivalPortalRole::Judge
            : FestivalPortalRole::Registrant;

        if (! $portalUser instanceof FestivalPortalUser) {
            if ($request->isMethod('GET') && $account instanceof Account) {
                $request->session()->put(
                    'festival_intended.'.$account->id.'.'.$expectedRole->value,
                    $request->fullUrl(),
                );
            }

            return redirect()->route(
                $expectedRole === FestivalPortalRole::Judge ? 'festival.judge.login' : 'festival.login',
                ['accountSlug' => $request->route('accountSlug')],
            );
        }

        abort_unless($account instanceof Account && $portalUser->account_id === $account->id, 404);

        if (! $portalUser->is_active) {
            Auth::guard('festival')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route(
                $portalUser->role === FestivalPortalRole::Judge ? 'festival.judge.login' : 'festival.login',
                $account->slug,
            )->withErrors(['email' => __('app.festival_profile_inactive')]);
        }

        return $next($request);
    }
}
