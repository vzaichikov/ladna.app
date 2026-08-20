<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterMcpOAuthClientRequest;
use App\Models\Account;
use App\Support\Mcp\McpOAuthMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;

class McpOAuthClientRegistrationController extends Controller
{
    public function __invoke(RegisterMcpOAuthClientRequest $request, Account $account, ClientRepository $clients): JsonResponse
    {
        $validated = $request->validated();
        $client = DB::transaction(function () use ($account, $clients, $validated) {
            $client = $clients->createAuthorizationCodeGrantClient(
                name: $validated['client_name'] ?? $validated['name'],
                redirectUris: $validated['redirect_uris'],
                confidential: false,
                user: null,
                enableDeviceFlow: false,
            );
            $client->forceFill(['account_id' => $account->id])->save();

            return $client;
        });

        return response()->json([
            'client_id' => (string) $client->id,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'redirect_uris' => $client->redirect_uris,
            'scope' => implode(' ', McpOAuthMetadata::AUTHORIZATION_SCOPES),
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }
}
