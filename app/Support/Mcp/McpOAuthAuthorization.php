<?php

namespace App\Support\Mcp;

use App\Models\Account;
use App\Models\McpOAuthConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;

class McpOAuthAuthorization
{
    public function __construct(private readonly McpOAuthToolAccessPolicy $accessPolicy) {}

    public function remember(Request $request, User $user, Client $client, string $authToken): Account
    {
        $account = Account::query()->findOrFail((int) $client->account_id);

        if (! $this->accessPolicy->eligibleMembership($account, $user)) {
            throw new AuthorizationException(__('app.mcp_oauth_not_available'));
        }

        $resource = $request->string('resource')->toString();

        if ($resource !== '' && ! hash_equals(
            route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]),
            rtrim($resource, '/'),
        )) {
            throw ValidationException::withMessages(['resource' => __('app.mcp_oauth_invalid_studio_link')]);
        }

        $request->session()->put($this->sessionKey($authToken), [
            'account_id' => $account->id,
            'user_id' => $user->id,
            'oauth_client_id' => (string) $client->getKey(),
            'client_name' => $client->name,
        ]);

        return $account;
    }

    public function approve(Request $request): McpOAuthConnection
    {
        $authToken = $request->string('auth_token')->toString();
        $pending = $request->session()->pull($this->sessionKey($authToken));

        if (! is_array($pending) || (int) ($pending['user_id'] ?? 0) !== (int) $request->user()?->id) {
            throw ValidationException::withMessages([
                'authorization' => __('app.mcp_oauth_authorization_expired'),
            ]);
        }

        $account = Account::query()->findOrFail((int) $pending['account_id']);
        $user = $request->user();

        if (! $user instanceof User || ! $this->accessPolicy->eligibleMembership($account, $user)) {
            throw new AuthorizationException(__('app.mcp_oauth_not_available'));
        }

        $client = Client::query()->lockForUpdate()->findOrFail((string) $pending['oauth_client_id']);

        if ($client->account_id !== null && (int) $client->account_id !== $account->id) {
            throw ValidationException::withMessages([
                'resource' => __('app.mcp_oauth_separate_studio_connection'),
            ]);
        }

        if ($client->account_id === null) {
            $client->forceFill(['account_id' => $account->id])->save();
        }

        return McpOAuthConnection::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'oauth_client_id' => (string) $client->getKey(),
            ],
            [
                'account_id' => $account->id,
                'client_name' => (string) $pending['client_name'],
                'revoked_at' => null,
            ],
        );
    }

    public function forget(Request $request): void
    {
        $request->session()->forget($this->sessionKey($request->string('auth_token')->toString()));
    }

    private function sessionKey(string $authToken): string
    {
        return 'mcp_oauth_authorizations.'.hash('sha256', $authToken);
    }
}
