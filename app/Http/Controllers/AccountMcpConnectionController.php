<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\McpOAuthConnection;
use App\Models\User;
use App\Support\AccountApiTokenAbilityAuthorizer;
use App\Support\Mcp\McpConnectionGuide;
use App\Support\Mcp\McpOAuthConnectionRevoker;
use App\Support\Mcp\McpOAuthToolAccessPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountMcpConnectionController extends Controller
{
    public function index(
        Request $request,
        Account $account,
        McpOAuthToolAccessPolicy $accessPolicy,
        AccountApiTokenAbilityAuthorizer $abilityAuthorizer,
        McpConnectionGuide $connectionGuide,
    ): View {
        $user = $this->eligibleUser($request, $account, $accessPolicy);
        $activeTab = in_array($request->query('tab'), ['ai', 'api'], true)
            ? $request->query('tab')
            : 'ai';
        $canManageApiKeys = $user->can('manageStudioSettings', $account);

        if ($activeTab === 'api') {
            abort_unless($canManageApiKeys, 403);
        }

        $apiTokens = $activeTab === 'api'
            ? $account->apiTokens()->latest()->get()
            : collect();
        $canManageTeamConnections = $canManageApiKeys;
        $connections = $activeTab === 'ai'
            ? $account->mcpOAuthConnections()
                ->with(['user:id,name,email'])
                ->whereNull('revoked_at')
                ->when(! $canManageTeamConnections, fn ($query) => $query->whereBelongsTo($user))
                ->latest('last_used_at')
                ->latest('id')
                ->get()
            : collect();

        return view('accounts.connections', [
            'account' => $account,
            'activeTab' => $activeTab,
            'apiTokens' => $apiTokens,
            'apiTokenAbilities' => $abilityAuthorizer->grantableAbilities($account, $user),
            'apiTokenSecretAccess' => $apiTokens->mapWithKeys(fn ($token): array => [
                $token->id => $abilityAuthorizer->canManageSecrets($account, $user, $token),
            ]),
            'canManageApiKeys' => $canManageApiKeys,
            'canManageTeamConnections' => $canManageTeamConnections,
            'connections' => $connections,
            'currentUser' => $user,
            'guide' => $connectionGuide->forAccount($account),
        ]);
    }

    public function legacyIndex(Request $request, Account $account, McpOAuthToolAccessPolicy $accessPolicy): RedirectResponse
    {
        $this->eligibleUser($request, $account, $accessPolicy);

        return redirect()->route('dashboard.accounts.connections.index', $account);
    }

    public function destroy(
        Request $request,
        Account $account,
        McpOAuthConnection $mcpOAuthConnection,
        McpOAuthConnectionRevoker $revoker,
        McpOAuthToolAccessPolicy $accessPolicy,
    ): RedirectResponse {
        $this->authorize('view', $account);
        abort_unless($mcpOAuthConnection->account_id === $account->id, 404);

        $user = $request->user();
        abort_unless($user instanceof User && $accessPolicy->eligibleMembership($account, $user), 403);

        $canRevoke = (
            $mcpOAuthConnection->user_id === $user->id
            || $user->can('manageStudioSettings', $account)
        );

        abort_unless($canRevoke, 403);
        $revoker->revoke($mcpOAuthConnection);

        return redirect()->route('dashboard.accounts.connections.index', $account)
            ->with('status', __('app.mcp_connection_removed'));
    }

    private function eligibleUser(Request $request, Account $account, McpOAuthToolAccessPolicy $accessPolicy): User
    {
        $this->authorize('view', $account);
        $user = $request->user();

        abort_unless($user instanceof User && $accessPolicy->eligibleMembership($account, $user), 403);

        return $user;
    }
}
