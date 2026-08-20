<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Ai\LadnaAssistantCapabilities;
use App\Support\Mcp\McpAccountContext;
use App\Support\Mcp\McpOAuthToolAccessPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Name('describe-ladna-skills')]
#[Description('Use when deciding which Ladna tool fits a studio request or when the user asks what Ladna can do. Returns a curated account-scoped capability description.')]
class DescribeLadnaSkillsTool extends Tool
{
    public function handle(
        Request $request,
        McpAccountContext $context,
        LadnaAssistantCapabilities $capabilities,
        McpOAuthToolAccessPolicy $accessPolicy,
    ): Response|ResponseFactory {
        $startedAt = now();
        $validated = $request->validate([
            'channel' => ['nullable', 'string', Rule::in(['dashboard_chat', 'telegram_owner', 'customer_bot_future'])],
        ]);

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpRead);

            $account = $context->account();
            $capabilityPayload = $capabilities->forMcp($validated['channel'] ?? null);

            if ($context->isOAuth()) {
                $availableToolNames = $accessPolicy->availableToolNames($account, $context->actorUser());
                $capabilityPayload['read_capabilities'] = collect($capabilityPayload['read_capabilities'])
                    ->filter(fn (array $capability): bool => array_intersect($capability['tools'] ?? [], $availableToolNames) !== [])
                    ->values()
                    ->all();
                $capabilityPayload['guided_dialogs'] = [];
                $capabilityPayload['mutating_actions'] = [];
            }

            $payload = [
                ...$capabilityPayload,
                'studio_scope' => [
                    'name' => $account->name,
                    'slug' => $account->slug,
                    'timezone' => $account->timezone ?: config('app.timezone'),
                    'scope_source' => $context->isOAuth() ? 'connected Ladna user' : 'account bearer token',
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
