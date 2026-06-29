<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Mcp\McpAccountContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('search-owner-help')]
#[Description('Searches Ladna owner help pages from the curated help config. Returns page slugs, titles, and summaries only.')]
class SearchOwnerHelpTool extends Tool
{
    public function handle(Request $request, McpAccountContext $context): Response|ResponseFactory
    {
        $startedAt = now();
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $context->ensureAbility(AccountApiTokenAbility::McpRead);

        try {
            $query = Str::of((string) $validated['query'])->lower()->squish()->toString();
            $limit = (int) ($validated['limit'] ?? 5);
            $pages = collect(config('help.pages', []))
                ->map(function (array $page, string $slug) use ($query): array {
                    $haystack = Str::of(implode(' ', [
                        $slug,
                        $page['title'] ?? '',
                        $page['summary'] ?? '',
                        collect($page['sections'] ?? [])->pluck('title')->implode(' '),
                    ]))->lower()->toString();

                    return [
                        'slug' => $slug,
                        'title' => $page['title'] ?? $slug,
                        'summary' => $page['summary'] ?? null,
                        'score' => str_contains($haystack, $query) ? 2 : (int) collect(explode(' ', $query))->filter(fn (string $term): bool => $term !== '' && str_contains($haystack, $term))->count(),
                    ];
                })
                ->filter(fn (array $page): bool => $page['score'] > 0)
                ->sortByDesc('score')
                ->take($limit)
                ->values()
                ->map(fn (array $page): array => collect($page)->except('score')->all())
                ->all();

            $payload = [
                'query' => $query,
                'results' => $pages,
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
