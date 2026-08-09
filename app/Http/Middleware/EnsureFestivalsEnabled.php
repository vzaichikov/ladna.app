<?php

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFestivalsEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->route('account');

        if (! $account instanceof Account) {
            $slug = $request->route('accountSlug');
            $account = is_string($slug) ? Account::active()->where('slug', $slug)->first() : null;
        }

        abort_unless($account instanceof Account && $account->enable_festivals, 404);
        $request->attributes->set('festivalAccount', $account);

        return $next($request);
    }
}
