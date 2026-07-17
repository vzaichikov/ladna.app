<?php

namespace App\Support\Mcp;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Models\Account;
use App\Models\AccountApiToken;
use App\Models\McpToolInvocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
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

    public function ensureAbility(AccountApiTokenAbility $ability): AccountApiToken
    {
        $token = $this->token();

        if (! $token->hasAbility($ability)) {
            throw new AuthorizationException(__('app.api_token_forbidden'));
        }

        if ($ability->mutatesAccountData() && $this->account()->isReadOnlyDemo()) {
            throw new HttpException(Response::HTTP_LOCKED, __('app.demo_readonly_message'));
        }

        return $token;
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
            'account_api_token_id' => $this->token()->id,
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
