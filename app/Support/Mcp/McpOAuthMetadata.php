<?php

namespace App\Support\Mcp;

use App\Models\Account;

class McpOAuthMetadata
{
    public const AUTHORIZATION_SCOPES = ['mcp:use', 'offline_access'];

    public function __construct(private readonly McpConnectionGuide $connectionGuide) {}

    /**
     * @return array<string, mixed>
     */
    public function protectedResource(Account $account): array
    {
        $guide = $this->connectionGuide->forAccount($account);

        return [
            'resource' => $guide['connection_url'],
            'resource_name' => $guide['connection_name'],
            'resource_documentation' => $guide['public_guide_url'],
            'authorization_servers' => [$this->issuer($account)],
            'scopes_supported' => ['mcp:use'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function legacyProtectedResource(): array
    {
        return [
            'resource' => route('mcp.ladna-studio'),
            'resource_name' => 'Ladna Studio MCP (service key)',
            'resource_documentation' => route('api-docs.show', ['tab' => 'mcp']),
            'scopes_supported' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizationServer(Account $account): array
    {
        return [
            'issuer' => $this->issuer($account),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => route('mcp.oauth.register', ['account' => $account->slug]),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => self::AUTHORIZATION_SCOPES,
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function globalAuthorizationServer(): array
    {
        return [
            'issuer' => url('/'),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => self::AUTHORIZATION_SCOPES,
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ];
    }

    private function issuer(Account $account): string
    {
        return url('/oauth/mcp/'.$account->slug);
    }
}
