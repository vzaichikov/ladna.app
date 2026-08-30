<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFestivalBattleApiUsesTls
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment(['local', 'testing']) && ! $request->isSecure()) {
            return response()->json([
                'message' => __('app.festival_battle_api_https_required'),
                'code' => 'https_required',
            ], Response::HTTP_UPGRADE_REQUIRED);
        }

        return $next($request);
    }
}
