<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Events\StudioEventToolData;
use App\Support\Mcp\McpAccountContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('get-events-overview')]
#[Description('Returns bounded event lifecycle, inventory, ticket, check-in, revenue, and refund-obligation summaries in the bearer token account scope without buyer contacts.')]
class GetEventsOverviewTool extends Tool
{
    public function handle(
        Request $request,
        McpAccountContext $context,
        StudioEventToolData $eventData,
    ): Response|ResponseFactory {
        $startedAt = now();
        $rawInput = $request->all(['date_from', 'date_to', 'status_bucket', 'location_id', 'query', 'limit']);
        $validated = null;

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpEventsRead);
            $validated = $request->validate([
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
                'status_bucket' => ['nullable', Rule::in($this->statusBuckets())],
                'location_id' => ['nullable', 'integer', 'min:1'],
                'query' => ['nullable', 'string', 'max:120'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);
            $payload = $eventData->overview($context->account(), $validated);

            $context->recordInvocation(
                'get-events-overview',
                AccountApiTokenAbility::McpEventsRead,
                McpToolInvocationStatus::Succeeded,
                $this->auditInput($validated),
                [
                    'status' => $payload['status'],
                    'returned' => $payload['returned'],
                    'truncated' => $payload['truncated'],
                ],
                null,
                $startedAt,
            );

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'get-events-overview',
                AccountApiTokenAbility::McpEventsRead,
                $this->invocationStatus($throwable),
                $this->auditInput($validated ?? $rawInput),
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
            'date_from' => $schema->string()->format('date')->description('Optional first event date in YYYY-MM-DD in the studio timezone. Defaults to today.'),
            'date_to' => $schema->string()->format('date')->description('Optional last event date in YYYY-MM-DD in the studio timezone. Defaults to 365 days ahead; maximum period is 366 days.'),
            'status_bucket' => $schema->string()->enum($this->statusBuckets())->description('Event lifecycle bucket.')->default('upcoming'),
            'location_id' => $schema->integer()->min(1)->description('Optional studio location ID.'),
            'query' => $schema->string()->max(120)->description('Optional event title or slug search.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum events to return.')->default(20),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function statusBuckets(): array
    {
        return ['upcoming', 'draft', 'past', 'cancelled', 'all'];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function auditInput(array $input): array
    {
        return [
            ...collect($input)->except('query')->all(),
            'query_applied' => filled($input['query'] ?? null),
        ];
    }

    private function auditError(Throwable $throwable): string
    {
        return $throwable instanceof AuthorizationException || $throwable instanceof ValidationException
            ? $throwable->getMessage()
            : 'Event overview failed.';
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
