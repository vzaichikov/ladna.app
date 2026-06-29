<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Mcp\McpAccountContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('get-owner-help-page')]
#[Description('Returns one Ladna owner help page by slug from the curated help config.')]
class GetOwnerHelpPageTool extends Tool
{
    public function handle(Request $request, McpAccountContext $context): Response|ResponseFactory
    {
        $startedAt = now();
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:80'],
        ]);

        $context->ensureAbility(AccountApiTokenAbility::McpRead);

        try {
            $slug = (string) $validated['slug'];
            $page = config("help.pages.{$slug}");

            if (! is_array($page)) {
                throw ValidationException::withMessages([
                    'slug' => __('app.mcp_help_page_not_found'),
                ]);
            }

            $payload = [
                'slug' => $slug,
                'title' => $page['title'] ?? $slug,
                'summary' => $page['summary'] ?? null,
                'sections' => $page['sections'] ?? [],
                'related' => $page['related'] ?? [],
                'updated_at' => config('help.updated_at'),
            ];

            $context->recordInvocation('get-owner-help-page', AccountApiTokenAbility::McpRead, McpToolInvocationStatus::Succeeded, $validated, $payload, null, $startedAt);

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation('get-owner-help-page', AccountApiTokenAbility::McpRead, McpToolInvocationStatus::Failed, $validated, null, $throwable->getMessage(), $startedAt);

            throw $throwable;
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Help page slug returned by search-owner-help.')->required(),
        ];
    }
}
