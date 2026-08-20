<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Events\StudioEventToolData;
use App\Support\Mcp\McpAccountContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
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
#[Name('get-event-summary')]
#[Description('Returns a read-only operational summary for one event ID in the connected studio scope, including ticket-type inventory and no buyer contacts.')]
class GetEventSummaryTool extends Tool
{
    public function handle(
        Request $request,
        McpAccountContext $context,
        StudioEventToolData $eventData,
    ): Response|ResponseFactory {
        $startedAt = now();
        $rawInput = $request->all(['event_id']);
        $validated = null;

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpEventsRead);
            $validated = $request->validate([
                'event_id' => ['required', 'integer', 'min:1'],
            ]);
            $payload = $eventData->summary($context->account(), (int) $validated['event_id']);

            $context->recordInvocation(
                'get-event-summary',
                AccountApiTokenAbility::McpEventsRead,
                McpToolInvocationStatus::Succeeded,
                $validated,
                ['status' => $payload['status']],
                null,
                $startedAt,
            );

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'get-event-summary',
                AccountApiTokenAbility::McpEventsRead,
                $this->invocationStatus($throwable),
                $validated ?? $rawInput,
                null,
                $this->auditError($throwable),
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
            'event_id' => $schema->integer()->min(1)->description('Event ID returned by get-events-overview.')->required(),
        ];
    }

    private function auditError(Throwable $throwable): string
    {
        return $throwable instanceof AuthorizationException || $throwable instanceof ValidationException
            ? $throwable->getMessage()
            : 'Event summary failed.';
    }

    private function invocationStatus(Throwable $throwable): McpToolInvocationStatus
    {
        return match (true) {
            $throwable instanceof AuthorizationException => McpToolInvocationStatus::Denied,
            $throwable instanceof ValidationException => McpToolInvocationStatus::Invalid,
            default => McpToolInvocationStatus::Failed,
        };
    }
}
