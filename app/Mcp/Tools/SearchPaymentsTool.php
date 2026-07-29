<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\EventOrderStatus;
use App\Enums\McpToolInvocationStatus;
use App\Models\StudioExpense;
use App\Support\Mcp\McpAccountContext;
use App\Support\Payments\StudioPaymentToolData;
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

#[Name('search-payments')]
#[Description('Searches bounded customer payments, event payments, operational expenses, deposits, and owner withdrawals in the bearer token account scope. Contacts are masked and gateway secrets are never returned.')]
class SearchPaymentsTool extends Tool
{
    public function handle(
        Request $request,
        McpAccountContext $context,
        StudioPaymentToolData $paymentData,
    ): Response|ResponseFactory {
        $startedAt = now();
        $rawInput = $request->all(['date_from', 'date_to', 'query', 'kind', 'status', 'location_id', 'limit']);
        $validated = null;

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpPaymentsRead);
            $validated = $request->validate([
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
                'query' => ['nullable', 'string', 'max:120'],
                'kind' => ['nullable', Rule::in($this->kinds())],
                'status' => ['nullable', Rule::in($this->statuses())],
                'location_id' => ['nullable', 'integer', 'min:1'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);
            $payload = $paymentData->search($context->account(), $validated);

            $context->recordInvocation(
                'search-payments',
                AccountApiTokenAbility::McpPaymentsRead,
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
                'search-payments',
                AccountApiTokenAbility::McpPaymentsRead,
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
            'date_from' => $schema->string()->format('date')->description('Optional first date in YYYY-MM-DD in the studio timezone. Defaults to today.'),
            'date_to' => $schema->string()->format('date')->description('Optional last date in YYYY-MM-DD in the studio timezone. Defaults to today; maximum period is 366 days.'),
            'query' => $schema->string()->max(120)->description('Optional customer, buyer, event, expense, or reference search. The value is not stored in the MCP audit log.'),
            'kind' => $schema->string()->enum($this->kinds())->description('Optional transaction kind.'),
            'status' => $schema->string()->enum($this->statuses())->description('Optional exact payment, order, or expense status.'),
            'location_id' => $schema->integer()->min(1)->description('Optional location ID from the studio profile.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum combined results.')->default(20),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function kinds(): array
    {
        return ['customer_payment', 'event_payment', 'operational_expense', 'cash_movement'];
    }

    /**
     * @return array<int, string>
     */
    private function statuses(): array
    {
        return array_values(array_unique([
            ...array_column(CustomerPurchaseStatus::cases(), 'value'),
            ...array_column(EventOrderStatus::cases(), 'value'),
            ...StudioExpense::statuses(),
        ]));
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
            : 'Payment search failed.';
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
