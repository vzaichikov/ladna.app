<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Mcp\McpAccountContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('get-studio-profile')]
#[Description('Returns studio profile, active locations, timezone, and opening hours for the bearer token account scope.')]
class GetStudioProfileTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, McpAccountContext $context): Response|ResponseFactory
    {
        $startedAt = now();
        $validated = $request->validate([]);

        $context->ensureAbility(AccountApiTokenAbility::McpRead);

        try {
            $account = $context->account()->loadMissing('locations');

            $payload = [
                'studio' => [
                    'name' => $account->name,
                    'slug' => $account->slug,
                    'timezone' => $account->timezone ?: config('app.timezone'),
                    'country_code' => $account->country_code,
                    'default_language' => $account->default_language,
                    'default_currency' => $account->default_currency,
                    'opening_hours' => $account->openingHours(),
                ],
                'locations' => $account->locations
                    ->where('is_active', true)
                    ->sortBy('name')
                    ->values()
                    ->map(fn ($location): array => [
                        'name' => $location->name,
                        'slug' => $location->slug,
                        'address' => $location->address,
                        'phone' => $location->phone,
                        'email' => $location->email,
                        'timezone' => $location->timezone ?: $account->timezone,
                    ])
                    ->all(),
            ];

            $context->recordInvocation(
                'get-studio-profile',
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
                'get-studio-profile',
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
        return [];
    }
}
