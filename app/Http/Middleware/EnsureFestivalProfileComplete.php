<?php

namespace App\Http\Middleware;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFestivalProfileComplete
{
    public function __construct(private readonly CustomerAuthAvailability $availability) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $portalUser = $request->user('festival');
        $account = $request->attributes->get('festivalAccount');
        $requiresVerifiedPhone = ! ($portalUser instanceof FestivalPortalUser
            && $portalUser->role === FestivalPortalRole::Registrant
            && $account instanceof Account
            && ! $this->availability->methodsFor($account)->otp);

        if ($portalUser instanceof FestivalPortalUser && ! $portalUser->profileIsComplete($requiresVerifiedPhone)) {
            if ($request->routeIs('festival.portal.profile.*', 'festival.portal.judge.profile.*', 'festival.portal.guest.profile.*', 'festival.portal.logout', 'festival.logout')) {
                return $next($request);
            }

            return redirect()->route(
                match ($portalUser->role) {
                    FestivalPortalRole::Registrant => 'festival.portal.profile.edit',
                    FestivalPortalRole::Judge => 'festival.portal.judge.profile.edit',
                    FestivalPortalRole::Guest => 'festival.portal.guest.profile.edit',
                },
                ['accountSlug' => $request->route('accountSlug')],
            )
                ->with('status', __('app.festival_profile_required'));
        }

        return $next($request);
    }
}
