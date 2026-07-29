<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\CustomerBookingLedgerInvestigation;
use App\Support\Mcp\McpAccountContext;
use App\Support\TrialClassPassEligibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('investigate-customer-booking-ledger')]
#[Description('Reconstructs a customer booking, class-pass, and outstanding-payment ledger in the bearer token account scope, including all-time ordinary trial-pass eligibility and audited manual-override qualification as of a supplied timestamp. This tool is strictly read-only.')]
class InvestigateCustomerBookingLedgerTool extends Tool
{
    public function handle(
        Request $request,
        McpAccountContext $context,
        CustomerBookingLedgerInvestigation $investigation,
    ): Response|ResponseFactory {
        $startedAt = now();
        $rawInput = $request->all(['customer_id', 'from_date', 'to_date', 'as_of', 'source']);
        $validated = null;

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpCustomersRead);
            $context->ensureAbility(AccountApiTokenAbility::McpClassPassesRead);
            $validated = $request->validate([
                'customer_id' => ['required', 'integer', 'min:1'],
                'from_date' => ['nullable', 'date_format:Y-m-d'],
                'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
                'as_of' => ['nullable', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
                'source' => ['nullable', Rule::in(TrialClassPassEligibility::sources())],
            ]);
            $this->ensurePeriodIsBounded($context, $validated);
            $payload = $investigation->investigate(
                $context->account(),
                (int) $validated['customer_id'],
                isset($validated['from_date']) ? (string) $validated['from_date'] : null,
                isset($validated['to_date']) ? (string) $validated['to_date'] : null,
                isset($validated['as_of']) ? (string) $validated['as_of'] : null,
                (string) ($validated['source'] ?? TrialClassPassEligibility::SourceManual),
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
            'customer_id' => $schema->integer()->min(1)->description('Customer ID returned by search-customers.')->required(),
            'from_date' => $schema->string()->format('date')->description('Optional first detailed-timeline date in YYYY-MM-DD, interpreted in the studio timezone.'),
            'to_date' => $schema->string()->format('date')->description('Optional last detailed-timeline date in YYYY-MM-DD, interpreted in the studio timezone. Maximum period is 366 days.'),
            'as_of' => $schema->string()->format('date-time')->description('Optional RFC3339 timestamp at or before now for all-time history and trial eligibility, interpreted in the studio timezone. Defaults to now.'),
            'source' => $schema->string()->enum(TrialClassPassEligibility::sources())->default(TrialClassPassEligibility::SourceManual)->description('Trial issuance path to evaluate. Manual permits the single-unreserved-booking exception; online payment does not.'),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $boundedEvidence = fn (JsonSchema $schema) => $schema->object([
            'returned' => $schema->integer()->min(0)->required(),
            'total' => $schema->integer()->min(0)->required(),
            'limit' => $schema->integer()->enum([20])->required(),
            'truncated' => $schema->boolean()->required(),
            'items' => $schema->array()->max(20)->items($schema->object())->required(),
        ]);

        return [
            'status' => $schema->string()->enum(['found', 'not_found'])->required(),
            'customer_history_summary' => $schema->object([
                'evaluated_as_of' => $schema->string()->format('date-time')->required(),
                'timezone' => $schema->string()->required(),
                'counted_bookings_count' => $schema->integer()->min(0)->required(),
                'prior_attended_bookings_count' => $schema->integer()->min(0)->required(),
                'attendance_evidence_basis' => $schema->string()->required(),
                'attendance_evidence_complete' => $schema->boolean()->required(),
                'earliest_prior_attended_booking' => $schema->object()->nullable(),
                'supporting_bookings' => $boundedEvidence($schema)->required(),
            ]),
            'trial_eligibility' => $schema->object([
                'status' => $schema->string()->enum(['eligible', 'ineligible', 'not_configured'])->required(),
                'evaluated_as_of' => $schema->string()->format('date-time')->required(),
                'source' => $schema->string()->enum(TrialClassPassEligibility::sources())->required(),
                'timezone' => $schema->string()->required(),
                'reason_codes' => $schema->array()->items($schema->string())->required(),
                'counted_bookings_count' => $schema->integer()->min(0)->required(),
                'active_reservations_count' => $schema->integer()->min(0)->required(),
                'rule_reference_key' => $schema->string()->enum(['trial_class_pass_eligibility'])->required(),
                'evidence_complete' => $schema->boolean()->required(),
                'historical_reconstruction' => $schema->object()->required(),
                'supporting_bookings' => $boundedEvidence($schema)->required(),
                'trial_plans' => $schema->object([
                    'returned' => $schema->integer()->min(0)->required(),
                    'total' => $schema->integer()->min(0)->required(),
                    'limit' => $schema->integer()->enum([20])->required(),
                    'truncated' => $schema->boolean()->required(),
                    'items' => $schema->array()
                        ->max(20)
                        ->items($schema->object([
                            'class_pass_plan_id' => $schema->integer()->min(1)->required(),
                            'name' => $schema->string()->required(),
                            'is_active' => $schema->boolean()->required(),
                            'price_cents' => $schema->integer()->min(0)->required(),
                            'currency' => $schema->string()->required(),
                            'sessions_count' => $schema->integer()->min(1)->required(),
                            'current_configuration' => $schema->boolean()->enum([true])->required(),
                        ]))
                        ->required(),
                ])->required(),
            ]),
            'manual_override' => $schema->object([
                'status' => $schema->string()->enum(['available', 'unavailable', 'actor_permissions_not_evaluated'])->required(),
                'available' => $schema->boolean()->required(),
                'customer_qualifies' => $schema->boolean()->required(),
                'reason_codes' => $schema->array()->items($schema->string())->required(),
                'evaluated_as_of' => $schema->string()->format('date-time')->required(),
                'source' => $schema->string()->enum(TrialClassPassEligibility::sources())->required(),
                'timezone' => $schema->string()->required(),
                'normal_eligibility_status' => $schema->string()->enum(['eligible', 'ineligible'])->required(),
                'class_pass_history_count' => $schema->integer()->min(0)->required(),
                'successful_payments_count' => $schema->integer()->min(0)->required(),
                'actor_permissions_evaluated' => $schema->boolean()->required(),
                'actor_has_required_permissions' => $schema->boolean()->nullable()->required(),
                'required_permissions' => $schema->array()->items($schema->string())->required(),
                'requires_comment' => $schema->boolean()->enum([true])->required(),
                'human_exception' => $schema->boolean()->enum([true])->required(),
            ]),
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

    private function auditError(Throwable $throwable): string
    {
        return $throwable instanceof AuthorizationException
            || $throwable instanceof ValidationException
            || $throwable instanceof InvalidArgumentException
                ? $throwable->getMessage()
                : 'Customer booking-ledger investigation failed.';
    }

    private function invocationStatus(Throwable $throwable): McpToolInvocationStatus
    {
        return match (true) {
            $throwable instanceof AuthorizationException => McpToolInvocationStatus::Denied,
            $throwable instanceof ValidationException,
            $throwable instanceof InvalidArgumentException => McpToolInvocationStatus::Invalid,
            default => McpToolInvocationStatus::Failed,
        };
    }
}
