<?php

namespace App\Support;

use App\Enums\CustomerClassPassReservationStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassBookingCorrection;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassAdjustment;
use App\Models\CustomerClassPassReservation;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CustomerBookingLedgerInvestigation
{
    private const BookingLimit = 200;

    private const PassLimit = 50;

    private const AdjustmentLimit = 200;

    private const CorrectionLimit = 200;

    private const TimelineLimit = 500;

    /**
     * @return array<string, mixed>
     */
    public function investigate(
        Account $account,
        int $customerId,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        $timezone = $account->timezone ?: config('app.timezone');
        [$from, $to] = $this->dateRange($timezone, $fromDate, $toDate);
        $customer = Customer::query()
            ->whereBelongsTo($account)
            ->whereKey($customerId)
            ->first();

        if (! $customer) {
            return [
                'status' => 'not_found',
                'customer_id' => $customerId,
                'period' => $this->periodPayload($from, $to, $timezone),
            ];
        }

        $bookingsQuery = ClassBooking::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer)
            ->whereHas('scheduledClass', fn (Builder $query) => $query
                ->where('account_id', $account->id)
                ->whereBetween('starts_at', [$from, $to]))
            ->with([
                'scheduledClass:id,account_id,location_id,room_id,class_type_id,trainer_id,title,starts_at,ends_at,status',
                'scheduledClass.location:id,name',
                'scheduledClass.room:id,name',
                'scheduledClass.classType:id,name',
                'scheduledClass.trainer:id,name',
            ])
            ->select([
                'id',
                'account_id',
                'scheduled_class_id',
                'customer_id',
                'booked_by_actor_name',
                'booked_by_actor_role',
                'status',
                'attended_at',
                'skip_class_pass_reservation',
                'corrected_removed_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy(
                ScheduledClass::query()
                    ->select('starts_at')
                    ->whereColumn('scheduled_classes.id', 'class_bookings.scheduled_class_id')
                    ->limit(1),
            )
            ->orderBy('id');
        $bookingCount = (clone $bookingsQuery)->count();
        $bookings = $bookingsQuery->limit(self::BookingLimit)->get();
        $bookingIds = $bookings->modelKeys();

        $reservations = CustomerClassPassReservation::query()
            ->where('account_id', $account->id)
            ->whereIn('class_booking_id', $bookingIds)
            ->with('customerClassPass:id,account_id,customer_id,code,plan_name,purchased_at,created_at')
            ->orderBy('id')
            ->get();
        $reservationPassIds = $reservations->pluck('customer_class_pass_id')->unique()->values()->all();

        $passesQuery = CustomerClassPass::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer)
            ->where(function (Builder $query) use ($from, $to, $reservationPassIds): void {
                $query->where('is_active', true)
                    ->orWhereBetween('purchased_at', [$from, $to])
                    ->orWhereBetween('closed_at', [$from, $to])
                    ->orWhereHas('reservations.scheduledClass', fn (Builder $query) => $query
                        ->whereBetween('starts_at', [$from, $to]));

                if ($reservationPassIds !== []) {
                    $query->orWhereIn('id', $reservationPassIds);
                }
            })
            ->with([
                'classPassPlan.classTypes',
                'classPassPlan.trainerTypes',
                'classPassPlan.rooms',
            ])
            ->select([
                'id',
                'account_id',
                'customer_id',
                'class_pass_plan_id',
                'code',
                'source',
                'issued_by_actor_name',
                'issued_by_actor_role',
                'status',
                'plan_name',
                'sessions_count',
                'reserved_sessions_count',
                'used_sessions_count',
                'purchased_at',
                'opened_at',
                'expires_at',
                'usable_until_at',
                'closed_at',
                'is_active',
                'created_at',
                'updated_at',
            ])
            ->orderByDesc('purchased_at')
            ->orderByDesc('id');
        $passCount = (clone $passesQuery)->count();
        $passes = $passesQuery->limit(self::PassLimit)->get()
            ->sortBy(fn (CustomerClassPass $pass): string => ($pass->purchased_at?->toISOString() ?? '').'-'.str_pad((string) $pass->id, 20, '0', STR_PAD_LEFT))
            ->values();
        $passIds = $passes->modelKeys();

        $adjustmentsQuery = CustomerClassPassAdjustment::query()
            ->where('account_id', $account->id)
            ->whereIn('customer_class_pass_id', $passIds)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->orderBy('id');
        $adjustmentCount = (clone $adjustmentsQuery)->count();
        $adjustments = $adjustmentsQuery->limit(self::AdjustmentLimit)->get();

        $correctionsQuery = ClassBookingCorrection::query()
            ->where('account_id', $account->id)
            ->whereHas('scheduledClass', fn (Builder $query) => $query
                ->where('account_id', $account->id)
                ->whereBetween('starts_at', [$from, $to]))
            ->where(function (Builder $query) use ($customer, $bookingIds): void {
                $query->where('old_customer_id', $customer->id)
                    ->orWhere('new_customer_id', $customer->id);

                if ($bookingIds !== []) {
                    $query->orWhereIn('class_booking_id', $bookingIds);
                }
            })
            ->with('scheduledClass:id,account_id,title,starts_at')
            ->orderBy('created_at')
            ->orderBy('id');
        $correctionCount = (clone $correctionsQuery)->count();
        $corrections = $correctionsQuery->limit(self::CorrectionLimit)->get();
        $ledgerCounters = $this->ledgerCounters($account, $passIds);
        $truncation = [
            'bookings' => $this->truncation($bookingCount, self::BookingLimit),
            'passes' => $this->truncation($passCount, self::PassLimit),
            'adjustments' => $this->truncation($adjustmentCount, self::AdjustmentLimit),
            'corrections' => $this->truncation($correctionCount, self::CorrectionLimit),
        ];
        $complete = collect($truncation)->every(fn (array $value): bool => ! $value['truncated']);
        $findings = $this->findings($bookings, $reservations, $passes, $ledgerCounters, $complete);
        $timeline = $this->timeline($passes, $bookings, $reservations, $adjustments, $corrections, $timezone);

        return [
            'status' => 'found',
            'customer' => [
                'customer_id' => $customer->id,
                'name' => $customer->name,
            ],
            'period' => $this->periodPayload($from, $to, $timezone),
            'summary' => [
                'bookings_count' => $bookingCount,
                'passes_count' => $passCount,
                'adjustments_count' => $adjustmentCount,
                'corrections_count' => $correctionCount,
                'has_detected_anomalies' => collect($findings)->contains(
                    fn (array $finding): bool => in_array($finding['severity'], ['warning', 'error'], true),
                ),
                'evidence_complete' => $complete && ! $timeline['truncated'],
            ],
            'passes' => $passes
                ->map(fn (CustomerClassPass $pass): array => $this->passPayload(
                    $pass,
                    $ledgerCounters[$pass->id] ?? ['reserved' => 0, 'used' => 0, 'released' => 0],
                    $timezone,
                ))
                ->all(),
            'bookings' => $bookings
                ->map(fn (ClassBooking $booking): array => $this->bookingPayload(
                    $booking,
                    $reservations->firstWhere('class_booking_id', $booking->id),
                    $timezone,
                ))
                ->all(),
            'adjustments' => $adjustments
                ->map(fn (CustomerClassPassAdjustment $adjustment): array => $this->adjustmentPayload($adjustment, $timezone))
                ->all(),
            'corrections' => $corrections
                ->map(fn (ClassBookingCorrection $correction): array => $this->correctionPayload($correction, $timezone))
                ->all(),
            'findings' => $findings,
            'timeline' => $timeline['events'],
            'truncation' => [
                ...$truncation,
                'timeline' => [
                    'returned' => count($timeline['events']),
                    'limit' => self::TimelineLimit,
                    'truncated' => $timeline['truncated'],
                ],
            ],
            'invariants' => [
                'one_booking_per_customer_and_class' => 'database_unique_constraint',
                'one_reservation_per_booking' => 'database_unique_constraint',
                'pass_counters_are_compared_with_reservation_ledger' => true,
            ],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(string $timezone, ?string $fromDate, ?string $toDate): array
    {
        $today = now($timezone)->startOfDay();
        $from = $fromDate
            ? Carbon::createFromFormat('Y-m-d', $fromDate, $timezone)->startOfDay()
            : $today->copy()->subDays(90);
        $to = $toDate
            ? Carbon::createFromFormat('Y-m-d', $toDate, $timezone)->endOfDay()
            : $today->copy()->addDays(30)->endOfDay();

        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('The investigation end date must be on or after the start date.');
        }

        if ($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) > 366) {
            throw new InvalidArgumentException('The investigation period may not exceed 366 days.');
        }

        return [$from->utc(), $to->utc()];
    }

    /**
     * @param  array<int, int>  $passIds
     * @return array<int, array{reserved: int, used: int, released: int}>
     */
    private function ledgerCounters(Account $account, array $passIds): array
    {
        $counters = [];

        CustomerClassPassReservation::query()
            ->whereBelongsTo($account)
            ->whereIn('customer_class_pass_id', $passIds)
            ->selectRaw('customer_class_pass_id, status, count(*) as reservations_count')
            ->groupBy('customer_class_pass_id', 'status')
            ->get()
            ->each(function (CustomerClassPassReservation $reservation) use (&$counters): void {
                $status = $reservation->status instanceof CustomerClassPassReservationStatus
                    ? $reservation->status->value
                    : (string) $reservation->status;
                $counters[$reservation->customer_class_pass_id] ??= ['reserved' => 0, 'used' => 0, 'released' => 0];
                $counters[$reservation->customer_class_pass_id][$status] = (int) $reservation->reservations_count;
            });

        return $counters;
    }

    /**
     * @param  Collection<int, ClassBooking>  $bookings
     * @param  Collection<int, CustomerClassPassReservation>  $reservations
     * @param  Collection<int, CustomerClassPass>  $passes
     * @param  array<int, array{reserved: int, used: int, released: int}>  $ledgerCounters
     * @return array<int, array<string, mixed>>
     */
    private function findings(
        Collection $bookings,
        Collection $reservations,
        Collection $passes,
        array $ledgerCounters,
        bool $complete,
    ): array {
        $findings = [];

        $bookings
            ->groupBy('scheduled_class_id')
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->each(function (Collection $duplicates, int $scheduledClassId) use (&$findings): void {
                $findings[] = [
                    'code' => 'duplicate_customer_booking',
                    'severity' => 'error',
                    'message' => 'More than one booking exists for this customer and scheduled class.',
                    'evidence' => [
                        'scheduled_class_id' => $scheduledClassId,
                        'booking_ids' => $duplicates->modelKeys(),
                    ],
                ];
            });

        $reservations
            ->groupBy('class_booking_id')
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->each(function (Collection $duplicates, int $bookingId) use (&$findings): void {
                $findings[] = [
                    'code' => 'duplicate_booking_reservation',
                    'severity' => 'error',
                    'message' => 'More than one pass reservation exists for one booking.',
                    'evidence' => [
                        'booking_id' => $bookingId,
                        'reservation_ids' => $duplicates->modelKeys(),
                    ],
                ];
            });

        foreach ($passes as $pass) {
            $counters = $ledgerCounters[$pass->id] ?? ['reserved' => 0, 'used' => 0, 'released' => 0];

            if ((int) $pass->reserved_sessions_count !== $counters['reserved']
                || (int) $pass->used_sessions_count !== $counters['used']) {
                $findings[] = [
                    'code' => 'class_pass_counter_mismatch',
                    'severity' => 'warning',
                    'message' => 'Stored pass counters do not match the reservation ledger.',
                    'evidence' => [
                        'customer_class_pass_id' => $pass->id,
                        'pass_code' => $pass->code,
                        'stored_reserved' => (int) $pass->reserved_sessions_count,
                        'ledger_reserved' => $counters['reserved'],
                        'stored_used' => (int) $pass->used_sessions_count,
                        'ledger_used' => $counters['used'],
                    ],
                ];
            }
        }

        foreach ($bookings as $booking) {
            $reservation = $reservations->firstWhere('class_booking_id', $booking->id);
            $hasActiveReservation = $reservation
                && in_array($reservation->status, [
                    CustomerClassPassReservationStatus::Reserved,
                    CustomerClassPassReservationStatus::Used,
                ], true);

            if (! $hasActiveReservation
                && ! $booking->skip_class_pass_reservation
                && ! $booking->corrected_removed_at
                && $booking->scheduledClass?->status?->value === 'scheduled') {
                $findings[] = [
                    'code' => 'unreserved_booking',
                    'severity' => 'warning',
                    'message' => 'The booking has no reserved or used class-pass reservation.',
                    'evidence' => [
                        'booking_id' => $booking->id,
                        'scheduled_class_id' => $booking->scheduled_class_id,
                        'reservation_id' => $reservation?->id,
                        'reservation_status' => $reservation?->status?->value,
                    ],
                ];
            }

            if (! $reservation) {
                continue;
            }

            $pass = $reservation->customerClassPass;

            if (! $pass
                || (int) $pass->account_id !== (int) $booking->account_id
                || (int) $pass->customer_id !== (int) $booking->customer_id) {
                $findings[] = [
                    'code' => 'reservation_customer_or_account_mismatch',
                    'severity' => 'error',
                    'message' => 'The reservation pass does not belong to the booking customer and account.',
                    'evidence' => [
                        'booking_id' => $booking->id,
                        'reservation_id' => $reservation->id,
                        'customer_class_pass_id' => $reservation->customer_class_pass_id,
                    ],
                ];
            }

            if ((int) $reservation->scheduled_class_id !== (int) $booking->scheduled_class_id) {
                $findings[] = [
                    'code' => 'reservation_scheduled_class_mismatch',
                    'severity' => 'error',
                    'message' => 'The reservation and booking point to different scheduled classes.',
                    'evidence' => [
                        'booking_id' => $booking->id,
                        'reservation_id' => $reservation->id,
                        'booking_scheduled_class_id' => $booking->scheduled_class_id,
                        'reservation_scheduled_class_id' => $reservation->scheduled_class_id,
                    ],
                ];
            }

            if ($pass?->created_at
                && $booking->created_at?->lessThan($pass->created_at)
                && $reservation->created_at?->greaterThanOrEqualTo($pass->created_at->copy()->subSeconds(2))
                && $reservation->created_at?->lessThanOrEqualTo($pass->created_at->copy()->addMinutes(5))) {
                $findings[] = [
                    'code' => 'booking_consistent_with_issuance_backfill',
                    'severity' => 'info',
                    'message' => 'The booking existed before the pass, and its reservation was created with the pass issuance. This is consistent with automatic issuance backfill.',
                    'evidence' => [
                        'booking_id' => $booking->id,
                        'customer_class_pass_id' => $pass->id,
                        'pass_code' => $pass->code,
                        'booking_created_at' => $booking->created_at->toISOString(),
                        'pass_created_at' => $pass->created_at->toISOString(),
                        'reservation_created_at' => $reservation->created_at->toISOString(),
                    ],
                ];
            }
        }

        if ($complete && ! collect($findings)->contains(
            fn (array $finding): bool => in_array($finding['severity'], ['warning', 'error'], true),
        )) {
            $findings[] = [
                'code' => 'no_detected_ledger_inconsistencies',
                'severity' => 'info',
                'message' => 'No duplicate booking, duplicate reservation, tenant mismatch, class mismatch, unreserved booking, or pass counter mismatch was detected in the requested period.',
                'evidence' => [],
            ];
        }

        return $findings;
    }

    /**
     * @param  Collection<int, CustomerClassPass>  $passes
     * @param  Collection<int, ClassBooking>  $bookings
     * @param  Collection<int, CustomerClassPassReservation>  $reservations
     * @param  Collection<int, CustomerClassPassAdjustment>  $adjustments
     * @param  Collection<int, ClassBookingCorrection>  $corrections
     * @return array{events: array<int, array<string, mixed>>, truncated: bool}
     */
    private function timeline(
        Collection $passes,
        Collection $bookings,
        Collection $reservations,
        Collection $adjustments,
        Collection $corrections,
        string $timezone,
    ): array {
        $events = collect();

        foreach ($passes as $pass) {
            $events->push([
                'type' => 'class_pass_issued',
                'occurred_at' => $this->datetime($pass->created_at ?? $pass->purchased_at, $timezone),
                'customer_class_pass_id' => $pass->id,
                'pass_code' => $pass->code,
                'actor' => $this->actor($pass->issued_by_actor_name, $pass->issued_by_actor_role),
            ]);

            if ($pass->closed_at) {
                $events->push([
                    'type' => 'class_pass_closed',
                    'occurred_at' => $this->datetime($pass->closed_at, $timezone),
                    'customer_class_pass_id' => $pass->id,
                    'pass_code' => $pass->code,
                    'status' => $pass->status->value,
                ]);
            }
        }

        foreach ($bookings as $booking) {
            $events->push([
                'type' => 'booking_created',
                'occurred_at' => $this->datetime($booking->created_at, $timezone),
                'booking_id' => $booking->id,
                'scheduled_class_id' => $booking->scheduled_class_id,
                'class_starts_at' => $this->datetime($booking->scheduledClass?->starts_at, $timezone),
                'current_status' => $booking->status->value,
                'actor' => $this->actor($booking->booked_by_actor_name, $booking->booked_by_actor_role),
            ]);
        }

        foreach ($reservations as $reservation) {
            $events->push([
                'type' => 'class_pass_reservation_created',
                'occurred_at' => $this->datetime($reservation->created_at ?? $reservation->reserved_at, $timezone),
                'reservation_id' => $reservation->id,
                'booking_id' => $reservation->class_booking_id,
                'customer_class_pass_id' => $reservation->customer_class_pass_id,
                'pass_code' => $reservation->customerClassPass?->code,
                'status' => $reservation->status->value,
            ]);
        }

        foreach ($adjustments as $adjustment) {
            $events->push([
                'type' => 'class_pass_adjusted',
                'occurred_at' => $this->datetime($adjustment->created_at, $timezone),
                'adjustment_id' => $adjustment->id,
                'customer_class_pass_id' => $adjustment->customer_class_pass_id,
                'adjustment_type' => $adjustment->adjustment_type->value,
                'actor' => $this->actor($adjustment->actor_name, $adjustment->actor_role),
            ]);
        }

        foreach ($corrections as $correction) {
            $events->push([
                'type' => 'booking_correction_recorded',
                'occurred_at' => $this->datetime($correction->created_at, $timezone),
                'correction_id' => $correction->id,
                'booking_id' => $correction->class_booking_id,
                'scheduled_class_id' => $correction->scheduled_class_id,
                'action' => $correction->action,
                'pass_effect' => $correction->pass_effect,
                'actor' => $this->actor($correction->actor_name, $correction->actor_role),
            ]);
        }

        $sorted = $events
            ->filter(fn (array $event): bool => filled($event['occurred_at']))
            ->sortBy([
                ['occurred_at', 'asc'],
                ['type', 'asc'],
            ])
            ->values();

        return [
            'events' => $sorted->take(self::TimelineLimit)->all(),
            'truncated' => $sorted->count() > self::TimelineLimit,
        ];
    }

    /**
     * @param  array{reserved: int, used: int, released: int}  $ledgerCounters
     * @return array<string, mixed>
     */
    private function passPayload(CustomerClassPass $pass, array $ledgerCounters, string $timezone): array
    {
        return [
            'customer_class_pass_id' => $pass->id,
            'code' => $pass->code,
            'plan_name' => $pass->plan_name,
            'source' => $pass->source,
            'status' => $pass->status->value,
            'is_active' => $pass->is_active,
            'sessions_count' => (int) $pass->sessions_count,
            'stored_reserved_sessions_count' => (int) $pass->reserved_sessions_count,
            'stored_used_sessions_count' => (int) $pass->used_sessions_count,
            'ledger_reserved_sessions_count' => $ledgerCounters['reserved'],
            'ledger_used_sessions_count' => $ledgerCounters['used'],
            'ledger_released_sessions_count' => $ledgerCounters['released'],
            'purchased_at' => $this->datetime($pass->purchased_at, $timezone),
            'issued_at' => $this->datetime($pass->created_at, $timezone),
            'opened_at' => $this->datetime($pass->opened_at, $timezone),
            'expires_at' => $this->datetime($pass->expires_at, $timezone),
            'usable_until_at' => $this->datetime($pass->usable_until_at, $timezone),
            'closed_at' => $this->datetime($pass->closed_at, $timezone),
            'issued_by' => $this->actor($pass->issued_by_actor_name, $pass->issued_by_actor_role),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(
        ClassBooking $booking,
        ?CustomerClassPassReservation $reservation,
        string $timezone,
    ): array {
        return [
            'booking_id' => $booking->id,
            'status' => $booking->status->value,
            'created_at' => $this->datetime($booking->created_at, $timezone),
            'updated_at' => $this->datetime($booking->updated_at, $timezone),
            'attended_at' => $this->datetime($booking->attended_at, $timezone),
            'corrected_removed_at' => $this->datetime($booking->corrected_removed_at, $timezone),
            'skip_class_pass_reservation' => $booking->skip_class_pass_reservation,
            'booked_by' => $this->actor($booking->booked_by_actor_name, $booking->booked_by_actor_role),
            'scheduled_class' => [
                'scheduled_class_id' => $booking->scheduled_class_id,
                'title' => $booking->scheduledClass?->title,
                'class_type' => $booking->scheduledClass?->classType?->name,
                'starts_at' => $this->datetime($booking->scheduledClass?->starts_at, $timezone),
                'ends_at' => $this->datetime($booking->scheduledClass?->ends_at, $timezone),
                'status' => $booking->scheduledClass?->status?->value,
                'trainer' => $booking->scheduledClass?->trainer?->name,
                'location' => $booking->scheduledClass?->location?->name,
                'room' => $booking->scheduledClass?->room?->name,
            ],
            'reservation' => $reservation ? [
                'reservation_id' => $reservation->id,
                'customer_class_pass_id' => $reservation->customer_class_pass_id,
                'pass_code' => $reservation->customerClassPass?->code,
                'status' => $reservation->status->value,
                'reserved_at' => $this->datetime($reservation->reserved_at, $timezone),
                'used_at' => $this->datetime($reservation->used_at, $timezone),
                'released_at' => $this->datetime($reservation->released_at, $timezone),
                'created_at' => $this->datetime($reservation->created_at, $timezone),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adjustmentPayload(CustomerClassPassAdjustment $adjustment, string $timezone): array
    {
        return [
            'adjustment_id' => $adjustment->id,
            'customer_class_pass_id' => $adjustment->customer_class_pass_id,
            'adjustment_type' => $adjustment->adjustment_type->value,
            'sessions_delta' => $adjustment->sessions_delta,
            'previous_sessions_count' => $adjustment->previous_sessions_count,
            'new_sessions_count' => $adjustment->new_sessions_count,
            'days_delta' => $adjustment->days_delta,
            'previous_validity_days' => $adjustment->previous_validity_days,
            'new_validity_days' => $adjustment->new_validity_days,
            'previous_status' => $adjustment->previous_status,
            'new_status' => $adjustment->new_status,
            'freeze_started_at' => $this->datetime($adjustment->freeze_started_at, $timezone),
            'freeze_finished_at' => $this->datetime($adjustment->freeze_finished_at, $timezone),
            'freeze_days_count' => $adjustment->freeze_days_count,
            'actor' => $this->actor($adjustment->actor_name, $adjustment->actor_role),
            'created_at' => $this->datetime($adjustment->created_at, $timezone),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function correctionPayload(ClassBookingCorrection $correction, string $timezone): array
    {
        return [
            'correction_id' => $correction->id,
            'booking_id' => $correction->class_booking_id,
            'scheduled_class_id' => $correction->scheduled_class_id,
            'class_starts_at' => $this->datetime($correction->scheduledClass?->starts_at, $timezone),
            'action' => $correction->action,
            'pass_effect' => $correction->pass_effect,
            'previous_customer_class_pass_id' => $correction->previous_customer_class_pass_id,
            'new_customer_class_pass_id' => $correction->new_customer_class_pass_id,
            'previous_booking_status' => $correction->previous_booking_status,
            'new_booking_status' => $correction->new_booking_status,
            'previous_reservation_status' => $correction->previous_reservation_status,
            'new_reservation_status' => $correction->new_reservation_status,
            'actor' => $this->actor($correction->actor_name, $correction->actor_role),
            'created_at' => $this->datetime($correction->created_at, $timezone),
        ];
    }

    /**
     * @return array{name: string|null, role: string|null}|null
     */
    private function actor(?string $name, ?string $role): ?array
    {
        if (blank($name) && blank($role)) {
            return null;
        }

        return [
            'name' => filled($name) ? $name : null,
            'role' => filled($role) ? $role : null,
        ];
    }

    private function datetime(mixed $value, string $timezone): ?string
    {
        if (! $value instanceof Carbon) {
            return null;
        }

        return $value->copy()->timezone($timezone)->toIso8601String();
    }

    /**
     * @return array{from_date: string, to_date: string, timezone: string}
     */
    private function periodPayload(Carbon $from, Carbon $to, string $timezone): array
    {
        return [
            'from_date' => $from->copy()->timezone($timezone)->toDateString(),
            'to_date' => $to->copy()->timezone($timezone)->toDateString(),
            'timezone' => $timezone,
        ];
    }

    /**
     * @return array{returned: int, total: int, limit: int, truncated: bool}
     */
    private function truncation(int $total, int $limit): array
    {
        return [
            'returned' => min($total, $limit),
            'total' => $total,
            'limit' => $limit,
            'truncated' => $total > $limit,
        ];
    }
}
