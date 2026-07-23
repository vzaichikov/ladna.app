<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\CustomerBookingLedgerInvestigation;
use App\Support\Mcp\McpAccountContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('investigate-customer-booking-ledger')]
#[Description('Reconstructs a customer booking and class-pass ledger in the bearer token account scope, including deterministic inconsistency and issuance-backfill findings. This tool is strictly read-only.')]
class InvestigateCustomerBookingLedgerTool extends Tool
{
    public function handle(
        Request $request,
        McpAccountContext $context,
        CustomerBookingLedgerInvestigation $investigation,
    ): Response|ResponseFactory {
        $startedAt = now();
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'min:1'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpCustomersRead);
            $context->ensureAbility(AccountApiTokenAbility::McpClassPassesRead);
            $this->ensurePeriodIsBounded($context, $validated);
            $payload = $investigation->investigate(
                $context->account(),
                (int) $validated['customer_id'],
                isset($validated['from_date']) ? (string) $validated['from_date'] : null,
                isset($validated['to_date']) ? (string) $validated['to_date'] : null,
            );

            $context->recordInvocation(
                'investigate-customer-booking-ledger',
                AccountApiTokenAbility::McpClassPassesRead,
                McpToolInvocationStatus::Succeeded,
                $validated,
                $payload,
                null,
                $startedAt,
            );

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'investigate-customer-booking-ledger',
                AccountApiTokenAbility::McpClassPassesRead,
                $throwable instanceof AuthorizationException ? McpToolInvocationStatus::Denied : McpToolInvocationStatus::Failed,
                $validated,
                null,
                $throwable->getMessage(),
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
            'customer_id' => $schema->integer()->min(1)->description('Customer ID returned by search-customers.')->required(),
            'from_date' => $schema->string()->format('date')->description('Optional first calendar date in YYYY-MM-DD, interpreted in the studio timezone.'),
            'to_date' => $schema->string()->format('date')->description('Optional last calendar date in YYYY-MM-DD, interpreted in the studio timezone. Maximum period is 366 days.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function ensurePeriodIsBounded(McpAccountContext $context, array $validated): void
    {
        $timezone = $context->account()->timezone ?: config('app.timezone');
        $today = now($timezone)->startOfDay();
        $from = isset($validated['from_date'])
            ? Carbon::createFromFormat('Y-m-d', (string) $validated['from_date'], $timezone)->startOfDay()
            : $today->copy()->subDays(90);
        $to = isset($validated['to_date'])
            ? Carbon::createFromFormat('Y-m-d', (string) $validated['to_date'], $timezone)->startOfDay()
            : $today->copy()->addDays(30);

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages([
                'to_date' => 'The investigation period may not exceed 366 days.',
            ]);
        }
    }
}
