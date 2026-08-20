<?php

namespace App\Http\Controllers;

use App\Support\Mcp\McpOAuthAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

class McpOAuthApprovalController extends ApproveAuthorizationController
{
    public function __construct(
        AuthorizationServer $server,
        private readonly McpOAuthAuthorization $authorization,
    ) {
        parent::__construct($server);
    }

    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        return DB::transaction(function () use ($request, $psrResponse): Response {
            $this->authorization->approve($request);

            return parent::approve($request, $psrResponse);
        });
    }
}
