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
use Throwable;

#[Name('get-payroll-overview')]
#[Description('Returns payroll cadence and immutable closed or voided payroll-run snapshots. Closing a run accrues salary but does not record an actual payout.')]
class GetPayrollOverviewTool extends Tool
{
    public function handle(Request $request, McpAccountContext $context, FinanceMcpData $financeData): Response|ResponseFactory
    {
        $startedAt = now();
        $rawInput = $request->all(['limit']);
        $validated = null;

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpPayrollRead);
            $validated = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);
            $payload = $financeData->payrollOverview($context->account(), $validated);

            $context->recordInvocation(
                'get-payroll-overview',
                AccountApiTokenAbility::McpPayrollRead,
                McpToolInvocationStatus::Succeeded,
                $validated,
                ['status' => $payload['status'], 'returned' => $payload['returned'], 'truncated' => $payload['truncated']],
                null,
                $startedAt,
            );

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'get-payroll-overview',
                AccountApiTokenAbility::McpPayrollRead,
                $this->invocationStatus($throwable),
                $validated ?? $rawInput,
                null,
                $this->auditError($throwable),
                $startedAt,
            );

            throw $throwable;
        }
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum payroll runs to return.')->default(20),
        ];
    }

    private function auditError(Throwable $throwable): string
    {
        return $throwable instanceof AuthorizationException || $throwable instanceof ValidationException
            ? $throwable->getMessage()
            : 'Payroll overview failed.';
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
