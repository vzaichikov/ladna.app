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
#[Name('get-financial-report')]
#[Description('Returns the read-only studio financial report for the active finance epoch: payments, refunds, operational expenses, and owner cash movements. Owner deposits and withdrawals are excluded from operating result.')]
class GetFinancialReportTool extends Tool
{
    public function handle(Request $request, McpAccountContext $context, FinanceMcpData $financeData): Response|ResponseFactory
    {
        $startedAt = now();
        $rawInput = $request->all(['date_from', 'date_to', 'location_id', 'limit']);
        $validated = null;

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpPaymentsRead);
            $validated = $request->validate($this->rules());
            $payload = $financeData->financialReport($context->account(), $validated);

            $context->recordInvocation(
                'get-financial-report',
                AccountApiTokenAbility::McpPaymentsRead,
                McpToolInvocationStatus::Succeeded,
                $validated,
                ['status' => $payload['status']],
                null,
                $startedAt,
            );

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'get-financial-report',
                AccountApiTokenAbility::McpPaymentsRead,
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
            'date_from' => $schema->string()->format('date')->description('Optional first date in the studio timezone. Defaults to the start of the current month and is clamped to the active finance epoch.'),
            'date_to' => $schema->string()->format('date')->description('Optional last date in the studio timezone. Defaults to today; maximum period is 366 days.'),
            'location_id' => $schema->integer()->min(1)->description('Optional operational studio location ID.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum rows returned per report section.')->default(20),
        ];
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    private function auditError(Throwable $throwable): string
    {
        return $throwable instanceof AuthorizationException || $throwable instanceof ValidationException
            ? $throwable->getMessage()
            : 'Financial report failed.';
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
