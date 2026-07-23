<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\LadnaBusinessLogicReference;
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

#[Name('get-business-logic-reference')]
#[Description('Returns curated Ladna business logic references by allow-listed key. Does not read arbitrary file paths.')]
class GetBusinessLogicReferenceTool extends Tool
{
    public function handle(
        Request $request,
        McpAccountContext $context,
        LadnaBusinessLogicReference $references,
    ): Response|ResponseFactory {
        $startedAt = now();
        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in($references->keys())],
        ]);

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpLogicRead);
            $payload = [
                'key' => $validated['key'],
                'reference' => $references->find((string) $validated['key']),
                'available_keys' => $references->keys(),
            ];

            $context->recordInvocation('get-business-logic-reference', AccountApiTokenAbility::McpLogicRead, McpToolInvocationStatus::Succeeded, $validated, $payload, null, $startedAt);

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'get-business-logic-reference',
                AccountApiTokenAbility::McpLogicRead,
                $throwable instanceof AuthorizationException
                    ? McpToolInvocationStatus::Denied
                    : McpToolInvocationStatus::Failed,
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
            'key' => $schema->string()->description('One of the allow-listed Ladna business-rule reference keys returned in available_keys.')->required(),
        ];
    }
}
