<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Finance\FinanceMcpData;
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
#[Name('get-cashbox-overview')]
#[Description('Returns current cashbox balances from the latest actual reconciliation plus subsequent append-only cash ledger movements in the active finance epoch.')]
class GetCashboxOverviewTool extends Tool
{
    public function handle(Request $request, McpAccountContext $context, FinanceMcpData $financeData): Response|ResponseFactory
    {
        $startedAt = now();
        $rawInput = $request->all(['location_id', 'currency']);
        $validated = null;

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpCashflowRead);
            $validated = $request->validate([
                'location_id' => ['nullable', 'integer', 'min:1'],
                'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            ]);
            $payload = $financeData->cashboxOverview($context->account(), $validated);

            $context->recordInvocation(
                'get-cashbox-overview',
                AccountApiTokenAbility::McpCashflowRead,
                McpToolInvocationStatus::Succeeded,
                $validated,
                ['status' => $payload['status'], 'cashboxes' => count($payload['cashboxes'])],
                null,
                $startedAt,
            );

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'get-cashbox-overview',
                AccountApiTokenAbility::McpCashflowRead,
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
            'location_id' => $schema->integer()->min(1)->description('Optional cashbox location ID.'),
            'currency' => $schema->string()->min(3)->max(3)->description('Optional ISO-style three-letter currency code.'),
        ];
    }

    private function auditError(Throwable $throwable): string
    {
        return $throwable instanceof AuthorizationException || $throwable instanceof ValidationException
            ? $throwable->getMessage()
            : 'Cashbox overview failed.';
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
