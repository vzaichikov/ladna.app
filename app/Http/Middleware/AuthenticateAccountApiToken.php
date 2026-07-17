<?php

namespace App\Http\Middleware;

use App\Enums\AccountApiTokenAbility;
use App\Models\AccountApiToken;
use App\Support\AccountApiTokenIssuer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAccountApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json(['message' => __('app.api_token_missing')], Response::HTTP_UNAUTHORIZED);
        }

        $accountApiToken = AccountApiToken::with('account')
            ->where('token_hash', app(AccountApiTokenIssuer::class)->hash($bearerToken))
            ->where('is_active', true)
            ->first();

        if (! $accountApiToken || ! $accountApiToken->account) {
            return response()->json(['message' => __('app.api_token_invalid')], Response::HTTP_UNAUTHORIZED);
        }

        foreach ($abilities as $ability) {
            if (! $accountApiToken->hasAbility($ability)) {
                return response()->json(['message' => __('app.api_token_forbidden')], Response::HTTP_FORBIDDEN);
            }
        }

        $requestsMutation = collect($abilities)
            ->map(fn (string $ability): ?AccountApiTokenAbility => AccountApiTokenAbility::tryFrom($ability))
            ->contains(fn (?AccountApiTokenAbility $ability): bool => $ability?->mutatesAccountData() ?? false);

        if ($requestsMutation && $accountApiToken->account->isReadOnlyDemo()) {
            return response()->json([
                'message' => __('app.demo_readonly_message'),
                'code' => 'demo_readonly',
            ], Response::HTTP_LOCKED);
        }

        if (! $accountApiToken->account->isReadOnlyDemo()) {
            $accountApiToken->forceFill(['last_used_at' => now()])->save();
        }
        $request->attributes->set('accountApiToken', $accountApiToken);
        $request->attributes->set('account', $accountApiToken->account);

        return $next($request);
    }
}
