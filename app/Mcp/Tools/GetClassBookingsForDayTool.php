<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Mcp\McpAccountContext;
use App\Support\StudioClassScheduleDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('get-class-bookings-for-day')]
#[Description('Returns scheduled classes for a studio calendar day with trainer, location, room, capacity, and booked customer names in the bearer token account scope.')]
class GetClassBookingsForDayTool extends Tool
{
    public function handle(Request $request, McpAccountContext $context, StudioClassScheduleDetails $details): Response|ResponseFactory
    {
        $startedAt = now();
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'include_cancelled_classes' => ['nullable', 'boolean'],
            'include_cancelled_bookings' => ['nullable', 'boolean'],
        ]);

        try {
            $context->ensureAbility(AccountApiTokenAbility::McpCustomersRead);

            $account = $context->account();
            $timezone = $account->timezone ?: config('app.timezone');
            $day = Carbon::createFromFormat('Y-m-d', (string) $validated['date'], $timezone)->startOfDay();
            $payload = $details->forDay(
                $account,
                $day,
                (bool) ($validated['include_cancelled_classes'] ?? false),
                (bool) ($validated['include_cancelled_bookings'] ?? false),
            );

            $context->recordInvocation(
                'get-class-bookings-for-day',
                AccountApiTokenAbility::McpCustomersRead,
                McpToolInvocationStatus::Succeeded,
                $validated,
                $payload,
                null,
                $startedAt,
            );

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation(
                'get-class-bookings-for-day',
                AccountApiTokenAbility::McpCustomersRead,
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
            'date' => $schema->string()->format('date')->description('Calendar date in YYYY-MM-DD format, interpreted in the studio timezone.')->required(),
            'include_cancelled_classes' => $schema->boolean()->description('Include cancelled scheduled classes.')->default(false),
            'include_cancelled_bookings' => $schema->boolean()->description('Include cancelled customer bookings in each class booking list.')->default(false),
        ];
    }
}
