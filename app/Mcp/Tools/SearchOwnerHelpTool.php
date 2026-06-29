<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Mcp\McpAccountContext;
use App\Support\OwnerHelpIndex;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('search-owner-help')]
#[Description('Searches Ladna owner help pages from the curated help config. Returns matching page slugs, titles, summaries, sections, and short fragments.')]
class SearchOwnerHelpTool extends Tool
{
    public function handle(Request $request, McpAccountContext $context, OwnerHelpIndex $helpIndex): Response|ResponseFactory
    {
        $startedAt = now();
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $context->ensureAbility(AccountApiTokenAbility::McpRead);

        try {
            $query = (string) $validated['query'];
            $limit = (int) ($validated['limit'] ?? 5);

            $payload = [
                'query' => $query,
                'results' => $helpIndex->search($query, $limit),
            ];

            $context->recordInvocation('search-owner-help', AccountApiTokenAbility::McpRead, McpToolInvocationStatus::Succeeded, $validated, $payload, null, $startedAt);

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation('search-owner-help', AccountApiTokenAbility::McpRead, McpToolInvocationStatus::Failed, $validated, null, $throwable->getMessage(), $startedAt);

            throw $throwable;
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Owner help search query.')->required(),
            'limit' => $schema->integer()->description('Maximum number of matching help pages, from 1 to 10.')->default(5),
        ];
    }
}
