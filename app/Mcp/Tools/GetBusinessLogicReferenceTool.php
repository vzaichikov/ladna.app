<?php

namespace App\Mcp\Tools;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Support\Mcp\McpAccountContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('get-business-logic-reference')]
#[Description('Returns curated Ladna business logic references by allow-listed key. Does not read arbitrary file paths.')]
class GetBusinessLogicReferenceTool extends Tool
{
    /**
     * @var array<string, array{path: string, symbol: string, summary: string, excerpt: string}>
     */
    private const REFERENCES = [
        'quick_booking' => [
            'path' => 'app/Actions/CreateQuickBooking.php',
            'symbol' => 'App\\Actions\\CreateQuickBooking::execute',
            'summary' => 'Creates group or manual quick bookings through existing customer resolution, capacity/manual availability checks, pass reservation, lead conversion, and booking notification.',
            'excerpt' => 'Group bookings use an existing scheduled class and capacity check; private/rental bookings create a manual scheduled class after ManualQuickBookingAvailability confirms the slot.',
        ],
        'class_booking_status_cancel' => [
            'path' => 'app/Http/Controllers/ClassBookingController.php',
            'symbol' => 'App\\Http\\Controllers\\ClassBookingController::update',
            'summary' => 'Changes booking status, blocks cancellation after cutoff, reconciles class pass reservation, and sends cancellation or booking notifications.',
            'excerpt' => 'Cancelled bookings are status changes, not deletes. Deletion is a separate controller action and is not used by assistant actions.',
        ],
        'manual_availability' => [
            'path' => 'app/Support/ManualQuickBookingAvailability.php',
            'symbol' => 'App\\Support\\ManualQuickBookingAvailability',
            'summary' => 'Calculates allowed manual private lesson and room rental start times using studio opening hours, room/class/trainer constraints, and existing classes.',
            'excerpt' => 'Quick booking must call this availability layer before creating manual scheduled classes.',
        ],
        'class_pass_reservation' => [
            'path' => 'app/Actions/ReserveCustomerClassPassForBooking.php',
            'symbol' => 'App\\Actions\\ReserveCustomerClassPassForBooking::execute',
            'summary' => 'Finds and reserves the best active customer class pass for a booking, then keeps pass usage consistent through reconciliation actions.',
            'excerpt' => 'Booking creation reserves a pass; status changes and cancellations reconcile the reservation instead of manually editing pass counters.',
        ],
    ];

    public function handle(Request $request, McpAccountContext $context): Response|ResponseFactory
    {
        $startedAt = now();
        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys(self::REFERENCES))],
        ]);

        $context->ensureAbility(AccountApiTokenAbility::McpLogicRead);

        try {
            $payload = [
                'key' => $validated['key'],
                'reference' => self::REFERENCES[$validated['key']],
                'available_keys' => array_keys(self::REFERENCES),
            ];

            $context->recordInvocation('get-business-logic-reference', AccountApiTokenAbility::McpLogicRead, McpToolInvocationStatus::Succeeded, $validated, $payload, null, $startedAt);

            return Response::structured($payload);
        } catch (Throwable $throwable) {
            $context->recordInvocation('get-business-logic-reference', AccountApiTokenAbility::McpLogicRead, McpToolInvocationStatus::Failed, $validated, null, $throwable->getMessage(), $startedAt);

            throw $throwable;
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('One of: quick_booking, class_booking_status_cancel, manual_availability, class_pass_reservation.')->required(),
        ];
    }
}
