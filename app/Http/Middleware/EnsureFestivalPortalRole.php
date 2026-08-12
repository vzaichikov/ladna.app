<?php

namespace App\Http\Middleware;

use App\Enums\FestivalPortalRole;
use App\Models\FestivalPortalUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFestivalPortalRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $expectedRole = FestivalPortalRole::tryFrom($role);
        $portalUser = $request->user('festival');
        abort_unless($expectedRole && $portalUser instanceof FestivalPortalUser && $portalUser->role === $expectedRole && $portalUser->is_active, 403);

        return $next($request);
    }
}
