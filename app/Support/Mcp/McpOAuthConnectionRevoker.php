<?php

namespace App\Support\Mcp;

use App\Models\McpOAuthConnection;
use Laravel\Passport\Passport;

class McpOAuthConnectionRevoker
{
    public function revoke(McpOAuthConnection $connection): void
    {
        $accessTokens = Passport::token()->newQuery()
            ->where('user_id', $connection->user_id)
            ->where('client_id', $connection->oauth_client_id)
            ->get();

        if ($accessTokens->isNotEmpty()) {
            Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', $accessTokens->modelKeys())
                ->update(['revoked' => true]);

            Passport::token()->newQuery()
                ->whereKey($accessTokens->modelKeys())
                ->update(['revoked' => true]);
        }

        $connection->forceFill(['revoked_at' => now()])->save();
    }
}
