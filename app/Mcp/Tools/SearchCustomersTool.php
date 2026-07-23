<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\CustomerInvestigationSearch;
use App\Support\Mcp\McpAccountContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('search-customers')]
#[Description('Searches customers by name or phone fragment in the bearer token account scope and returns masked contact details for disambiguation.')]
class SearchCustomersTool extends Tool
{
    public function handle(
        Request $request,
        McpAccountContext $context,
        CustomerInvestigationSearch $customerSearch,
    ): Response|ResponseFactory {
        $startedAt = now();
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpCustomersRead);
            $payload = $customerSearch->search(
                $context->account(),
                (string) $validated['query'],
                (int) ($validated['limit'] ?? 5),
            );

            $context->recordInvocation(
                'search-customers',
                AccountApiTokenAbility::McpCustomersRead,
                McpToolInvocationStatus::Succeeded,
                $validated,
                $payload,
                null,
                $startedAt,
            );

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'search-customers',
                AccountApiTokenAbility::McpCustomersRead,
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
            'query' => $schema->string()->description('Customer name or phone fragment.')->required(),
            'limit' => $schema->integer()->min(1)->max(10)->description('Maximum candidates to return.')->default(5),
        ];
    }
}
