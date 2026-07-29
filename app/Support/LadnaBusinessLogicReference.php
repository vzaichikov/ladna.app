<?php

namespace App\Support;

class LadnaBusinessLogicReference
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
            'path' => 'app/Actions/CancelClassBooking.php',
            'symbol' => 'App\\Actions\\CancelClassBooking::execute',
            'summary' => 'Cancels an individual booking before the cutoff, keeps it for history, releases its class-pass reservation, and sends one cancellation notification.',
            'excerpt' => 'Web, mobile, and assistant cancellations use this action. Booking deletion and whole-class studio cancellation remain separate workflows.',
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
        'class_pass_issuance_backfill' => [
            'path' => 'app/Actions/ReconcileUnreservedCustomerBookingsForIssuedClassPass.php',
            'symbol' => 'App\\Actions\\ReconcileUnreservedCustomerBookingsForIssuedClassPass::execute',
            'summary' => 'Issuing a pass rechecks eligible non-cancelled unreserved bookings, while explicit manual normalization can also release legacy consumed cancellations.',
            'excerpt' => 'Cancelled bookings are never attached as used sessions. Legacy repairs require the owner-facing preview and Apply flow.',
        ],
        'class_pass_reservation_chronology' => [
            'path' => 'app/Actions/ReserveCustomerClassPassForBooking.php',
            'symbol' => 'App\\Actions\\ReserveCustomerClassPassForBooking::execute',
            'summary' => 'Eligible active passes are considered by purchased_at and then id, so the oldest suitable pass is consumed first.',
            'excerpt' => 'One booking has at most one reservation. Pass suitability includes account, class type, room, trainer type, time window, validity, and remaining sessions.',
        ],
        'trial_class_pass_eligibility' => [
            'path' => 'app/Support/TrialClassPassEligibility.php',
            'symbol' => 'App\\Support\\TrialClassPassEligibility::evaluate',
            'summary' => 'Evaluates trial class-pass eligibility from all non-corrected bookings for the customer in the studio account.',
            'excerpt' => 'Online issuance requires zero bookings. Manual issuance permits zero bookings or exactly one booking without an active reserved or used pass; cancelled, attended, future, and every other non-removed booking count.',
        ],
        'class_pass_normalization' => [
            'path' => 'app/Actions/NormalizeCustomerClassPasses.php',
            'symbol' => 'App\\Actions\\NormalizeCustomerClassPasses::forPass',
            'summary' => 'Rebuilds reserved and used counters from reservation ledger rows and closes used-up or expired passes.',
            'excerpt' => 'A non-cancelled reserved class becomes used only after the scheduled class end plus the studio 60-minute grace window. Cancelled bookings stay released.',
        ],
        'closed_booking_corrections' => [
            'path' => 'app/Actions/AddClosedClassBookingCorrection.php',
            'symbol' => 'App\\Actions\\AddClosedClassBookingCorrection::execute',
            'summary' => 'Records explicit correction history for additions or removals made after a class is closed.',
            'excerpt' => 'Corrections preserve actor snapshots, the booking status transition, and the exact pass/reservation effect instead of silently rewriting history.',
        ],
    ];

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys(self::REFERENCES);
    }

    /**
     * @return array{path: string, symbol: string, summary: string, excerpt: string}|null
     */
    public function find(string $key): ?array
    {
        return self::REFERENCES[$key] ?? null;
    }
}
