<?php

namespace App\Http\Middleware;

use App\Support\Mcp\McpOAuthMetadata;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Symfony\Component\HttpFoundation\Response;

class PrepareMcpOAuthAuthorization
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $scopes = array_values(array_unique(array_filter(
            preg_split('/\s+/', $request->string('scope')->toString()) ?: [],
        )));

        if (! in_array('mcp:use', $scopes, true)
            || array_diff($scopes, McpOAuthMetadata::AUTHORIZATION_SCOPES) !== []) {
            throw new AuthorizationException(__('app.mcp_oauth_not_available'));
        }

        $client = Client::query()->find($request->string('client_id')->toString());

        if (! $client || $client->account_id === null || $client->revoked) {
            throw new AuthorizationException(__('app.mcp_oauth_not_available'));
        }

        $prompts = preg_split('/\s+/', $request->string('prompt')->toString()) ?: [];
        $prompts = array_values(array_diff($prompts, ['none']));
        $prompts[] = 'consent';
        $request->merge(['prompt' => implode(' ', array_values(array_unique(array_filter($prompts))))]);

        return $next($request);
    }
}
