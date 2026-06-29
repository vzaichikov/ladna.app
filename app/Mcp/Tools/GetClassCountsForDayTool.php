<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\ScheduledClass;
use App\Support\Mcp\McpAccountContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('get-class-counts-for-day')]
#[Description('Returns studio class counts for a calendar day in the bearer token account scope.')]
class GetClassCountsForDayTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, McpAccountContext $context): Response|ResponseFactory
    {
        $startedAt = now();
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'include_cancelled' => ['nullable', 'boolean'],
        ]);

        $context->ensureAbility(AccountApiTokenAbility::McpRead);

        try {
            $account = $context->account();
            $timezone = $account->timezone ?: config('app.timezone');
            $day = Carbon::createFromFormat('Y-m-d', (string) $validated['date'], $timezone)->startOfDay();
            $start = $day->copy()->timezone('UTC');
            $end = $day->copy()->endOfDay()->timezone('UTC');
            $includeCancelled = (bool) ($validated['include_cancelled'] ?? false);

            $classes = ScheduledClass::query()
                ->whereBelongsTo($account)
                ->whereBetween('starts_at', [$start, $end])
                ->when(! $includeCancelled, fn ($query) => $query->where('status', ScheduledClassStatus::Scheduled->value))
                ->with(['location:id,account_id,name', 'classType:id,account_id,name,schedule_kind'])
                ->orderBy('starts_at')
                ->get();

            $payload = [
                'account' => [
                    'name' => $account->name,
                    'timezone' => $timezone,
                ],
                'date' => $day->toDateString(),
                'include_cancelled' => $includeCancelled,
                'total' => $classes->count(),
                'by_status' => $classes
                    ->countBy(fn (ScheduledClass $scheduledClass): string => $scheduledClass->status->value)
                    ->all(),
                'by_schedule_kind' => $classes
                    ->countBy(fn (ScheduledClass $scheduledClass): string => $scheduledClass->classType?->schedule_kind?->value ?? 'unknown')
                    ->all(),
                'by_location' => $classes
                    ->groupBy(fn (ScheduledClass $scheduledClass): string => (string) ($scheduledClass->location_id ?? 'none'))
                    ->map(fn ($locationClasses): array => [
                        'location_id' => $locationClasses->first()->location_id,
                        'location_name' => $locationClasses->first()->location?->name,
                        'total' => $locationClasses->count(),
                        'by_status' => $locationClasses
                            ->countBy(fn (ScheduledClass $scheduledClass): string => $scheduledClass->status->value)
                            ->all(),
                        'by_schedule_kind' => $locationClasses
                            ->countBy(fn (ScheduledClass $scheduledClass): string => $scheduledClass->classType?->schedule_kind?->value ?? 'unknown')
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ];

            $context->recordInvocation(
                'get-class-counts-for-day',
                AccountApiTokenAbility::McpRead,
                McpToolInvocationStatus::Succeeded,
                $validated,
                $payload,
                null,
                $startedAt,
            );

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'get-class-counts-for-day',
                AccountApiTokenAbility::McpRead,
                McpToolInvocationStatus::Failed,
                $validated,
                null,
                $throwable->getMessage(),
                $startedAt,
            );

            throw $throwable;
        }
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->format('date')->description('Calendar date in YYYY-MM-DD format, interpreted in the studio timezone.')->required(),
            'include_cancelled' => $schema->boolean()->description('Include cancelled classes in the count.')->default(false),
        ];
    }
}
