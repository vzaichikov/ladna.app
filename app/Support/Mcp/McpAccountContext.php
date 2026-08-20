<?php

namespace App\Support\Mcp;

use App\Enums\AccountApiTokenAbility;
use App\Enums\AccountRole;
use App\Enums\McpToolInvocationStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountApiToken;
use App\Models\AccountMembership;
use App\Models\McpOAuthConnection;
use App\Models\McpToolInvocation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class McpAccountContext
{
    public function account(): Account
    {
        $account = request()->attributes->get('account');

        if (! $account instanceof Account) {
            throw new AuthenticationException(__('app.api_token_missing'));
        }

        return $account;
    }

    public function token(): AccountApiToken
    {
        $token = request()->attributes->get('accountApiToken');

        if (! $token instanceof AccountApiToken) {
            throw new AuthenticationException(__('app.api_token_missing'));
        }

        return $token;
    }

    public function ensureAbility(
        AccountApiTokenAbility $ability,
        StudioPermission|array|null $oauthPermissions = null,
        bool $matchAll = true,
    ): AccountApiToken|McpOAuthConnection {
        if ($this->isOAuth()) {
            if (! app(McpOAuthToolAccessPolicy::class)->canUseAbility(
                $this->account(),
                $this->actorUser(),
                $ability,
                $oauthPermissions,
                $matchAll,
            )) {
                throw new AuthorizationException(__('app.api_token_forbidden'));
            }

            return $this->oauthConnection();
        }

        $token = $this->token();

        if (! $token->hasAbility($ability)) {
            throw new AuthorizationException(__('app.api_token_forbidden'));
        }

        if ($ability->mutatesAccountData() && $this->account()->isReadOnlyDemo()) {
            throw new HttpException(Response::HTTP_LOCKED, __('app.demo_readonly_message'));
        }

        return $token;
    }

    public function isOAuth(): bool
    {
        return request()->attributes->get('mcpOAuthConnection') instanceof McpOAuthConnection;
    }

    public function oauthConnection(): McpOAuthConnection
    {
        $connection = request()->attributes->get('mcpOAuthConnection');

        if (! $connection instanceof McpOAuthConnection) {
            throw new AuthenticationException(__('app.api_token_missing'));
        }

        return $connection;
    }

    public function actorUser(): User
    {
        $user = request()->attributes->get('mcpActorUser');

        if (! $user instanceof User) {
            throw new AuthenticationException(__('app.api_token_missing'));
        }

        return $user;
    }

    public function membership(): AccountMembership
    {
        $membership = request()->attributes->get('accountMembership');

        if (! $membership instanceof AccountMembership) {
            throw new AuthenticationException(__('app.api_token_missing'));
        }

        return $membership;
    }

    public function constrainScheduledClassesForActor(Builder $query): Builder
    {
        if (! $this->isOAuth() || $this->membership()->role !== AccountRole::Trainer) {
            return $query;
        }

        $trainerId = $this->account()
            ->trainers()
            ->active()
            ->where('user_id', $this->actorUser()->id)
            ->value('id');

        if (! $trainerId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($trainerId): void {
            $query
                ->where('trainer_id', $trainerId)
                ->orWhereHas('additionalTrainers', fn (Builder $query): Builder => $query->whereKey($trainerId));
        });
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @param  array<string, mixed>|null  $output
     */
    public function recordInvocation(
        string $toolName,
        AccountApiTokenAbility $requiredAbility,
        McpToolInvocationStatus $status,
        ?array $input,
        ?array $output,
        ?string $errorMessage,
        Carbon $startedAt,
    ): void {
        if ($this->account()->isReadOnlyDemo()) {
            return;
        }

        McpToolInvocation::create([
            'account_id' => $this->account()->id,
            'account_api_token_id' => $this->isOAuth() ? null : $this->token()->id,
            'mcp_oauth_connection_id' => $this->isOAuth() ? $this->oauthConnection()->id : null,
            'actor_user_id' => $this->isOAuth() ? $this->actorUser()->id : null,
            'actor_role' => $this->isOAuth() ? $this->membership()->role->value : null,
            'actor_name' => $this->isOAuth() ? $this->actorUser()->name : null,
            'actor_email' => $this->isOAuth() ? $this->actorUser()->email : null,
            'credential_type' => $this->isOAuth() ? 'oauth_user' : 'account_api_token',
            'oauth_access_token_id' => $this->isOAuth() ? $this->actorUser()->currentAccessToken()?->oauth_access_token_id : null,
            'oauth_client_id' => $this->isOAuth() ? $this->actorUser()->currentAccessToken()?->oauth_client_id : null,
            'tool_name' => $toolName,
            'required_ability' => $requiredAbility->value,
            'status' => $status->value,
            'input' => $input,
            'output' => $output,
            'error_message' => $errorMessage,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }
}
