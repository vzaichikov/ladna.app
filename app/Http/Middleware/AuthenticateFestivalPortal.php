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
        $expectedRole = match (true) {
            $request->routeIs('festival.portal.judge.*', 'festival.portal.judging.*', 'festival.portal.battle-votes.*') => FestivalPortalRole::Judge,
            $request->routeIs('festival.portal.guest.*') => FestivalPortalRole::Guest,
            default => FestivalPortalRole::Registrant,
        };

        if (! $portalUser instanceof FestivalPortalUser) {
            if ($request->isMethod('GET') && $account instanceof Account) {
                $request->session()->put(
                    'festival_intended.'.$account->id.'.'.$expectedRole->value,
                    $request->fullUrl(),
                );
            }

            return redirect()->route(
                $this->loginRoute($expectedRole),
                ['accountSlug' => $request->route('accountSlug')],
            );
        }

        abort_unless($account instanceof Account && $portalUser->account_id === $account->id, 404);

        if (! $portalUser->is_active) {
            Auth::guard('festival')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route(
                $this->loginRoute($portalUser->role),
                $account->slug,
            )->withErrors(['email' => __('app.festival_profile_inactive')]);
        }

        return $next($request);
    }

    private function loginRoute(FestivalPortalRole $role): string
    {
        return match ($role) {
            FestivalPortalRole::Registrant => 'festival.login',
            FestivalPortalRole::Judge => 'festival.judge.login',
            FestivalPortalRole::Guest => 'festival.guest.login',
        };
    }
}
