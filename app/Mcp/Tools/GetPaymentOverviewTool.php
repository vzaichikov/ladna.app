<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Mcp\McpAccountContext;
use App\Support\Payments\StudioPaymentToolData;
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
#[Name('get-payment-overview')]
#[Description('Returns a read-only, currency-grouped overview of customer payments, customer refunds, event payments, outstanding class-pass balances, and fiscal failures for a bounded period in the active finance epoch.')]
class GetPaymentOverviewTool extends Tool
{
    public function handle(
        Request $request,
        McpAccountContext $context,
        StudioPaymentToolData $paymentData,
    ): Response|ResponseFactory {
        $startedAt = now();
        $rawInput = $request->all(['date_from', 'date_to', 'location_id']);
        $validated = null;

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpPaymentsRead);
            $validated = $request->validate([
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
                'location_id' => ['nullable', 'integer', 'min:1'],
            ]);
            $payload = $paymentData->overview($context->account(), $validated);

            $context->recordInvocation(
                'get-payment-overview',
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
                'get-payment-overview',
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
            'date_from' => $schema->string()->format('date')->description('Optional first date in YYYY-MM-DD in the studio timezone. Defaults to today.'),
            'date_to' => $schema->string()->format('date')->description('Optional last date in YYYY-MM-DD in the studio timezone. Defaults to today; maximum period is 366 days.'),
            'location_id' => $schema->integer()->min(1)->description('Optional location ID from the studio profile.'),
        ];
    }

    private function auditError(Throwable $throwable): string
    {
        return $throwable instanceof AuthorizationException || $throwable instanceof ValidationException
            ? $throwable->getMessage()
            : 'Payment overview failed.';
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
