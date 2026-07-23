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
        'class_pass_issuance_backfill' => [
            'path' => 'app/Actions/ReconcileUnreservedCustomerBookingsForIssuedClassPass.php',
            'symbol' => 'App\\Actions\\ReconcileUnreservedCustomerBookingsForIssuedClassPass::execute',
            'summary' => 'Issuing a pass rechecks the customer’s unreserved bookings and attaches eligible bookings in chronological order.',
            'excerpt' => 'An existing booking may receive a reservation immediately after a new pass is issued. This is expected backfill, not a second booking.',
        ],
        'class_pass_reservation_chronology' => [
            'path' => 'app/Actions/ReserveCustomerClassPassForBooking.php',
            'symbol' => 'App\\Actions\\ReserveCustomerClassPassForBooking::execute',
            'summary' => 'Eligible active passes are considered by purchased_at and then id, so the oldest suitable pass is consumed first.',
            'excerpt' => 'One booking has at most one reservation. Pass suitability includes account, class type, room, trainer type, time window, validity, and remaining sessions.',
        ],
        'class_pass_normalization' => [
            'path' => 'app/Actions/NormalizeCustomerClassPasses.php',
            'symbol' => 'App\\Actions\\NormalizeCustomerClassPasses::forPass',
            'summary' => 'Rebuilds reserved and used counters from reservation ledger rows and closes used-up or expired passes.',
            'excerpt' => 'A reserved class becomes used only after the scheduled class end plus the studio 60-minute grace window. The pass closes when ledger usage reaches its session snapshot.',
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
