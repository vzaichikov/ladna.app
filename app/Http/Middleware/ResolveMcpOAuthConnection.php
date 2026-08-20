<?php

namespace App\Http\Middleware;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\McpOAuthConnection;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Symfony\Component\HttpFoundation\Response;

class ResolveMcpOAuthConnection
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');
        $accessToken = $user instanceof User ? $user->currentAccessToken() : null;
        $account = Account::query()->where('slug', (string) $request->route('accountSlug'))->firstOrFail();

        if (! $user instanceof User || ! $accessToken || ! $user->tokenCan('mcp:use')) {
            throw new AuthorizationException(__('app.mcp_oauth_not_available'));
        }

        $connection = McpOAuthConnection::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($user)
            ->where('oauth_client_id', $accessToken->oauth_client_id)
            ->whereNull('revoked_at')
            ->first();
        $clientBelongsToAccount = Client::query()
            ->whereKey($accessToken->oauth_client_id)
            ->where('account_id', $account->id)
            ->where('revoked', false)
            ->exists();
        $membership = $account->membershipFor($user);

        if (! $connection || ! $clientBelongsToAccount || ! $membership || $membership->role === AccountRole::EventFestivalStaff) {
            throw new AuthorizationException(__('app.mcp_oauth_not_available'));
        }

        $request->attributes->set('account', $account);
        $request->attributes->set('mcpOAuthConnection', $connection);
        $request->attributes->set('mcpActorUser', $user);
        $request->attributes->set('accountMembership', $membership);

        if (! $account->isReadOnlyDemo()) {
            $connection->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }
}
