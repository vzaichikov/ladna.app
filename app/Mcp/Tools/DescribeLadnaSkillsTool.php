<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Ai\LadnaAssistantCapabilities;
use App\Support\Mcp\McpAccountContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('describe-ladna-skills')]
#[Description('Returns a curated account-scoped description of what the Ladna assistant can read, explain, analyze, and prepare for confirmation.')]
class DescribeLadnaSkillsTool extends Tool
{
    public function handle(Request $request, McpAccountContext $context, LadnaAssistantCapabilities $capabilities): Response|ResponseFactory
    {
        $startedAt = now();
        $validated = $request->validate([
            'channel' => ['nullable', 'string', Rule::in(['dashboard_chat', 'telegram_owner', 'customer_bot_future'])],
        ]);

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpRead);

            $account = $context->account();
            $payload = [
                ...$capabilities->forMcp($validated['channel'] ?? null),
                'studio_scope' => [
                    'name' => $account->name,
                    'slug' => $account->slug,
                    'timezone' => $account->timezone ?: config('app.timezone'),
                    'scope_source' => 'account bearer token',
                ],
            ];

            $context->recordInvocation(
                'describe-ladna-skills',
                AccountApiTokenAbility::McpRead,
                McpToolInvocationStatus::Succeeded,
                $validated,
                $payload,
                null,
                $startedAt,
            );

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'describe-ladna-skills',
                AccountApiTokenAbility::McpRead,
                $throwable instanceof AuthorizationException ? McpToolInvocationStatus::Denied : McpToolInvocationStatus::Failed,
                $validated,
                null,
                $throwable->getMessage(),
                $startedAt,
            );

            throw $throwable;
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()->description('Optional channel hint: dashboard_chat, telegram_owner, or customer_bot_future.'),
        ];
    }
}
