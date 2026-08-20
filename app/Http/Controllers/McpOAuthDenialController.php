<?php

namespace App\Http\Controllers;

use App\Support\Mcp\McpOAuthAuthorization;
use Illuminate\Http\Request;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

class McpOAuthDenialController extends DenyAuthorizationController
{
    public function __construct(
        AuthorizationServer $server,
        private readonly McpOAuthAuthorization $authorization,
    ) {
        parent::__construct($server);
    }

    public function deny(Request $request, ResponseInterface $psrResponse): Response
    {
        $this->authorization->forget($request);

        return parent::deny($request, $psrResponse);
    }
}
