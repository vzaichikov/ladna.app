<?php

namespace App\Http\Middleware;

use App\Models\FestivalPortalUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFestivalProfileComplete
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $portalUser = $request->user('festival');

        if ($portalUser instanceof FestivalPortalUser && ! $portalUser->profileIsComplete()) {
            if ($request->routeIs('festival.portal.profile.*', 'festival.logout')) {
                return $next($request);
            }

            return redirect()->route('festival.portal.profile.edit', ['accountSlug' => $request->route('accountSlug')])
                ->with('status', __('app.festival_profile_required'));
        }

        return $next($request);
    }
}
