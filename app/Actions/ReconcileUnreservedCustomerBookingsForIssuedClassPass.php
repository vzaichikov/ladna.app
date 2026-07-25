<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\ScheduledClass;
use App\Support\UnreservedClassPassBookingIssues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class ReconcileUnreservedCustomerBookingsForIssuedClassPass
{
    public function __construct(
        private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
        private readonly UnreservedClassPassBookingIssues $unreservedClassPassBookingIssues,
    ) {}

    public function execute(CustomerClassPass $customerClassPass): int
    {
        return $this->executeForAccountCustomer(
            (int) $customerClassPass->account_id,
            (int) $customerClassPass->customer_id,
            repairCancelledReservations: false,
        );
    }

    public function executeForCustomer(Customer $customer, bool $repairCancelledReservations = false): int
    {
        return $this->executeForAccountCustomer(
            (int) $customer->account_id,
            (int) $customer->id,
            $repairCancelledReservations,
        );
    }

    /**
     * @return array{
     *     passes: array<int, array{pass: CustomerClassPass, reserved_count: int, used_count: int, released_count: int, bookings: array<int, array{booking: ClassBooking, reservation_status: CustomerClassPassReservationStatus}>}>,
     *     totals: array{reserved: int, used: int, released: int},
     *     has_changes: bool
     * }
     */
    public function previewForCustomer(Customer $customer): array
    {
        $customerClassPasses = $this->activeCustomerClassPasses((int) $customer->account_id, (int) $customer->id);
        $cancelledBookings = $this->cancelledReservationBookings((int) $customer->account_id, (int) $customer->id);
        $cancelledReservationIds = $cancelledBookings
            ->pluck('classPassReservation.id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();
        $repairPasses = $cancelledBookings
            ->pluck('classPassReservation.customerClassPass')
            ->filter()
            ->unique('id')
            ->values();
        $previewPasses = $customerClassPasses
            ->concat($repairPasses)
            ->unique('id')
            ->sortBy([
                ['purchased_at', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $summaries = $previewPasses
            ->mapWithKeys(fn (CustomerClassPass $customerClassPass): array => [
                $customerClassPass->id => [
                    'pass' => $customerClassPass,
                    'reserved_count' => 0,
                    'used_count' => 0,
                    'released_count' => 0,
                    'bookings' => [],
                ],
            ])
            ->all();
        $simulatedCounters = $previewPasses
            ->mapWithKeys(fn (CustomerClassPass $customerClassPass): array => [
                $customerClassPass->id => [
                    'reserved' => (int) $customerClassPass->reserved_sessions_count,
                    'used' => (int) $customerClassPass->used_sessions_count,
                ],
            ])
            ->all();

        foreach ($cancelledBookings as $cancelledBooking) {
            $reservation = $cancelledBooking->classPassReservation;
            $customerClassPass = $reservation?->customerClassPass;

            if (! $reservation || ! $customerClassPass || ! isset($summaries[$customerClassPass->id])) {
                continue;
            }

            $counterKey = $reservation->status === CustomerClassPassReservationStatus::Used ? 'used' : 'reserved';
            $simulatedCounters[$customerClassPass->id][$counterKey] = max(
                0,
                $simulatedCounters[$customerClassPass->id][$counterKey] - 1,
            );
            $summaries[$customerClassPass->id]['released_count']++;
            $summaries[$customerClassPass->id]['bookings'][] = [
                'booking' => $cancelledBooking,
                'reservation_status' => CustomerClassPassReservationStatus::Released,
            ];
        }

        $eligibleCustomerClassPasses = $this->simulatedEligiblePasses(
            $customerClassPasses,
            $repairPasses,
            $simulatedCounters,
            $cancelledReservationIds,
        );

        foreach ($this->candidateBookings((int) $customer->account_id, (int) $customer->id) as $classBooking) {
            if (! $classBooking->scheduledClass) {
                continue;
            }

            foreach ($eligibleCustomerClassPasses as $customerClassPass) {
                $simulatedPass = clone $customerClassPass;
                $simulatedPass->reserved_sessions_count = $simulatedCounters[$customerClassPass->id]['reserved'];
                $simulatedPass->used_sessions_count = $simulatedCounters[$customerClassPass->id]['used'];

                if (! $simulatedPass->canReserveFor($classBooking->scheduledClass)) {
                    continue;
                }

                $reservationStatus = $this->predictedReservationStatus($classBooking);
                $counterKey = $reservationStatus === CustomerClassPassReservationStatus::Used ? 'used' : 'reserved';
                $summaryKey = $reservationStatus === CustomerClassPassReservationStatus::Used ? 'used_count' : 'reserved_count';

                $simulatedCounters[$customerClassPass->id][$counterKey]++;
                $summaries[$customerClassPass->id][$summaryKey]++;
                $summaries[$customerClassPass->id]['bookings'][] = [
                    'booking' => $classBooking,
                    'reservation_status' => $reservationStatus,
                ];

                continue 2;
            }
        }

        $passes = array_values(array_filter(
            $summaries,
            fn (array $summary): bool => $summary['reserved_count'] > 0
                || $summary['used_count'] > 0
                || $summary['released_count'] > 0,
        ));
        $reservedTotal = array_sum(array_column($passes, 'reserved_count'));
        $usedTotal = array_sum(array_column($passes, 'used_count'));
        $releasedTotal = array_sum(array_column($passes, 'released_count'));

        return [
            'passes' => $passes,
            'totals' => [
                'reserved' => $reservedTotal,
                'used' => $usedTotal,
                'released' => $releasedTotal,
            ],
            'has_changes' => $reservedTotal > 0 || $usedTotal > 0 || $releasedTotal > 0,
        ];
    }

    private function executeForAccountCustomer(int $accountId, int $customerId, bool $repairCancelledReservations): int
    {
        return DB::transaction(function () use ($accountId, $customerId, $repairCancelledReservations): int {
            $this->lockCustomerBookings($accountId, $customerId);

            $releasedCount = $repairCancelledReservations
                ? $this->releaseCancelledReservations($accountId, $customerId)
                : 0;
            $customerClassPasses = $this->activeCustomerClassPasses($accountId, $customerId, lockForUpdate: true);

            if ($customerClassPasses->isEmpty()) {
                return $releasedCount;
            }

            $customerClassPassIds = $customerClassPasses->modelKeys();
            $classBookings = $this->ledgerCandidateBookings($accountId, $customerId, $customerClassPassIds);

            if ($classBookings->isEmpty()) {
                return $releasedCount;
            }

            $candidateBookingIds = $classBookings->modelKeys();
            $baseCounters = $this->baseReservationCounters($customerClassPassIds, $candidateBookingIds);

            foreach ($customerClassPasses as $customerClassPass) {
                $customerClassPass->reserved_sessions_count = $baseCounters[$customerClassPass->id]['reserved'] ?? 0;
                $customerClassPass->used_sessions_count = $baseCounters[$customerClassPass->id]['used'] ?? 0;
            }

            $reconciledCount = 0;

            foreach ($classBookings as $classBooking) {
                if (! $classBooking->scheduledClass) {
                    continue;
                }

                $reservationStatus = $this->predictedReservationStatus($classBooking);
                $customerClassPass = $customerClassPasses
                    ->first(fn (CustomerClassPass $candidate): bool => $candidate->canReserveFor($classBooking->scheduledClass));
                $reservation = $classBooking->classPassReservation()->lockForUpdate()->first();

                if (! $customerClassPass) {
                    if ($reservation && $this->reservationIsActiveForPasses($reservation, $customerClassPassIds)) {
                        $reservation->update([
                            'status' => CustomerClassPassReservationStatus::Released->value,
                            'released_at' => now(),
                            'used_at' => null,
                        ]);
                    }

                    continue;
                }

                $attributes = [
                    'account_id' => $classBooking->account_id,
                    'customer_class_pass_id' => $customerClassPass->id,
                    'class_booking_id' => $classBooking->id,
                    'scheduled_class_id' => $classBooking->scheduled_class_id,
                    'status' => $reservationStatus->value,
                    'reserved_at' => $reservation?->reserved_at ?? now(),
                    'used_at' => $reservationStatus === CustomerClassPassReservationStatus::Used ? $this->usedAt($classBooking) : null,
                    'released_at' => null,
                ];

                if ($reservation) {
                    $reservation->fill($attributes);
                    $reservationChanged = $reservation->isDirty();
                    $reservation->save();
                } else {
                    $customerClassPass->reservations()->create($attributes);
                    $reservationChanged = true;
                }

                if ($reservationStatus === CustomerClassPassReservationStatus::Used) {
                    $customerClassPass->used_sessions_count++;
                } else {
                    $customerClassPass->reserved_sessions_count++;
                }

                if ($reservationChanged) {
                    $reconciledCount++;
                }
            }

            $customerClassPasses
                ->each(fn (CustomerClassPass $pass): CustomerClassPass => $this->normalizeCustomerClassPasses->forPass($pass));

            return $releasedCount + $reconciledCount;
        }, attempts: 3);
    }

    private function lockCustomerBookings(int $accountId, int $customerId): void
    {
        ClassBooking::query()
            ->where('account_id', $accountId)
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * @param  array<int, int>  $customerClassPassIds
     * @return Collection<int, ClassBooking>
     */
    private function ledgerCandidateBookings(int $accountId, int $customerId, array $customerClassPassIds): Collection
    {
        $classBookingTable = (new ClassBooking)->getTable();
        $scheduledClassTable = (new ScheduledClass)->getTable();

        return ClassBooking::query()
            ->join($scheduledClassTable, "{$scheduledClassTable}.id", '=', "{$classBookingTable}.scheduled_class_id")
            ->where("{$classBookingTable}.account_id", $accountId)
            ->where("{$classBookingTable}.customer_id", $customerId)
            ->whereNull("{$classBookingTable}.corrected_removed_at")
            ->whereIn("{$classBookingTable}.status", [
                ClassBookingStatus::Booked->value,
                ClassBookingStatus::Attended->value,
                ClassBookingStatus::NoShow->value,
            ])
            ->where("{$classBookingTable}.skip_class_pass_reservation", false)
            ->where("{$scheduledClassTable}.account_id", $accountId)
            ->where("{$scheduledClassTable}.status", ScheduledClassStatus::Scheduled->value)
            ->where(function ($query) use ($customerClassPassIds): void {
                $query
                    ->whereDoesntHave('classPassReservation', fn ($query) => $query->whereIn('status', [
                        CustomerClassPassReservationStatus::Reserved->value,
                        CustomerClassPassReservationStatus::Used->value,
                    ]))
                    ->orWhereHas('classPassReservation', fn ($query) => $query
                        ->whereIn('customer_class_pass_id', $customerClassPassIds)
                        ->whereIn('status', [
                            CustomerClassPassReservationStatus::Reserved->value,
                            CustomerClassPassReservationStatus::Used->value,
                        ]));
            })
            ->select("{$classBookingTable}.*")
            ->with(['scheduledClass.classType', 'scheduledClass.trainer', 'scheduledClass.room', 'customer', 'classPassReservation'])
            ->orderBy("{$scheduledClassTable}.starts_at")
            ->orderBy("{$classBookingTable}.id")
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  array<int, int>  $customerClassPassIds
     * @param  array<int, int>  $candidateBookingIds
     * @return array<int, array{reserved: int, used: int}>
     */
    private function baseReservationCounters(array $customerClassPassIds, array $candidateBookingIds): array
    {
        $counters = [];

        CustomerClassPassReservation::query()
            ->whereIn('customer_class_pass_id', $customerClassPassIds)
            ->whereNotIn('class_booking_id', $candidateBookingIds)
            ->whereIn('status', [
                CustomerClassPassReservationStatus::Reserved->value,
                CustomerClassPassReservationStatus::Used->value,
            ])
            ->selectRaw('customer_class_pass_id, status, count(*) as reservations_count')
            ->groupBy('customer_class_pass_id', 'status')
            ->get()
            ->each(function (CustomerClassPassReservation $reservation) use (&$counters): void {
                $status = $reservation->status instanceof CustomerClassPassReservationStatus
                    ? $reservation->status->value
                    : (string) $reservation->status;
                $counterKey = $status === CustomerClassPassReservationStatus::Used->value ? 'used' : 'reserved';

                $counters[$reservation->customer_class_pass_id][$counterKey] = (int) $reservation->reservations_count;
            });

        return $counters;
    }

    /**
     * @param  array<int, int>  $customerClassPassIds
     */
    private function reservationIsActiveForPasses(CustomerClassPassReservation $reservation, array $customerClassPassIds): bool
    {
        return in_array($reservation->customer_class_pass_id, $customerClassPassIds, true)
            && in_array($reservation->status, [
                CustomerClassPassReservationStatus::Reserved,
                CustomerClassPassReservationStatus::Used,
            ], true);
    }

    /**
     * @return Collection<int, ClassBooking>
     */
    private function candidateBookings(int $accountId, int $customerId): Collection
    {
        return $this->unreservedClassPassBookingIssues->queryForAccountCustomer($accountId, $customerId)
            ->with([
                'scheduledClass.account',
                'scheduledClass.classType',
                'scheduledClass.location',
                'scheduledClass.trainer',
                'scheduledClass.room',
                'customer',
            ])
            ->get();
    }

    /**
     * @return Collection<int, ClassBooking>
     */
    private function cancelledReservationBookings(int $accountId, int $customerId): Collection
    {
        $scheduledClassTable = (new ScheduledClass)->getTable();
        $classBookingTable = (new ClassBooking)->getTable();

        return $this->cancelledReservationBookingsQuery($accountId, $customerId)
            ->with([
                'scheduledClass.account',
                'scheduledClass.classType',
                'scheduledClass.location',
                'scheduledClass.trainer',
                'scheduledClass.room',
                'customer',
                'classPassReservation.customerClassPass.classPassPlan.classTypes',
                'classPassReservation.customerClassPass.classPassPlan.trainerTypes',
                'classPassReservation.customerClassPass.classPassPlan.rooms',
                'classPassReservation.customerClassPass.reservations',
            ])
            ->orderBy("{$scheduledClassTable}.starts_at")
            ->orderBy("{$classBookingTable}.id")
            ->get();
    }

    private function cancelledReservationBookingsQuery(int $accountId, int $customerId): Builder
    {
        $classBookingTable = (new ClassBooking)->getTable();
        $scheduledClassTable = (new ScheduledClass)->getTable();

        return ClassBooking::query()
            ->join($scheduledClassTable, "{$scheduledClassTable}.id", '=', "{$classBookingTable}.scheduled_class_id")
            ->where("{$classBookingTable}.account_id", $accountId)
            ->where("{$classBookingTable}.customer_id", $customerId)
            ->where("{$classBookingTable}.status", ClassBookingStatus::Cancelled->value)
            ->whereNull("{$classBookingTable}.corrected_removed_at")
            ->where("{$classBookingTable}.skip_class_pass_reservation", false)
            ->where("{$scheduledClassTable}.account_id", $accountId)
            ->where("{$scheduledClassTable}.status", ScheduledClassStatus::Scheduled->value)
            ->whereHas('classPassReservation', fn (Builder $query) => $query
                ->whereIn('status', [
                    CustomerClassPassReservationStatus::Reserved->value,
                    CustomerClassPassReservationStatus::Used->value,
                ])
                ->whereHas('customerClassPass', fn (Builder $query) => $query
                    ->where('account_id', $accountId)
                    ->where('customer_id', $customerId)))
            ->select("{$classBookingTable}.*");
    }

    private function releaseCancelledReservations(int $accountId, int $customerId): int
    {
        $classBookingTable = (new ClassBooking)->getTable();
        $classBookings = $this->cancelledReservationBookingsQuery($accountId, $customerId)
            ->orderBy("{$classBookingTable}.id")
            ->lockForUpdate()
            ->get();

        if ($classBookings->isEmpty()) {
            return 0;
        }

        $reservations = CustomerClassPassReservation::query()
            ->whereIn('class_booking_id', $classBookings->modelKeys())
            ->whereIn('status', [
                CustomerClassPassReservationStatus::Reserved->value,
                CustomerClassPassReservationStatus::Used->value,
            ])
            ->whereHas('customerClassPass', fn (Builder $query) => $query
                ->where('account_id', $accountId)
                ->where('customer_id', $customerId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($reservations->isEmpty()) {
            return 0;
        }

        $customerClassPasses = CustomerClassPass::query()
            ->where('account_id', $accountId)
            ->where('customer_id', $customerId)
            ->whereIn('id', $reservations->pluck('customer_class_pass_id')->unique())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $releasedAt = now();

        foreach ($reservations as $reservation) {
            $reservation->update([
                'status' => CustomerClassPassReservationStatus::Released->value,
                'used_at' => null,
                'released_at' => $releasedAt,
            ]);
        }

        $customerClassPasses
            ->each(fn (CustomerClassPass $pass): CustomerClassPass => $this->normalizeCustomerClassPasses->forPass($pass));

        return $reservations->count();
    }

    /**
     * @param  Collection<int, CustomerClassPass>  $activePasses
     * @param  Collection<int, CustomerClassPass>  $repairPasses
     * @param  array<int, array{reserved: int, used: int}>  $simulatedCounters
     * @param  array<int, int>  $releasedReservationIds
     * @return Collection<int, CustomerClassPass>
     */
    private function simulatedEligiblePasses(
        Collection $activePasses,
        Collection $repairPasses,
        array $simulatedCounters,
        array $releasedReservationIds,
    ): Collection {
        $eligiblePasses = $activePasses->keyBy('id');

        foreach ($repairPasses as $repairPass) {
            if ($eligiblePasses->has($repairPass->id) || $repairPass->status !== CustomerClassPassStatus::UsedUp) {
                continue;
            }

            $usedReservations = $repairPass->reservations
                ->where('status', CustomerClassPassReservationStatus::Used)
                ->reject(fn (CustomerClassPassReservation $reservation): bool => in_array($reservation->id, $releasedReservationIds, true))
                ->filter(fn (CustomerClassPassReservation $reservation): bool => $reservation->used_at !== null)
                ->sortBy('used_at');
            $openedAt = $usedReservations->first()?->used_at;
            $expiresAt = $openedAt?->copy()->addDays((int) $repairPass->validity_days);
            $usableUntilAt = $repairPass->usableUntilAt();

            if (
                $usedReservations->count() >= (int) $repairPass->sessions_count
                || ($expiresAt && $expiresAt->lessThanOrEqualTo(now()))
                || ($usableUntilAt && $usableUntilAt->lessThanOrEqualTo(now()))
            ) {
                continue;
            }

            $simulatedPass = clone $repairPass;
            $simulatedPass->forceFill([
                'reserved_sessions_count' => $simulatedCounters[$repairPass->id]['reserved'],
                'used_sessions_count' => $simulatedCounters[$repairPass->id]['used'],
                'opened_at' => $openedAt,
                'expires_at' => $expiresAt,
                'status' => CustomerClassPassStatus::Active->value,
                'is_active' => true,
                'closed_at' => null,
            ]);
            $eligiblePasses->put($simulatedPass->id, $simulatedPass);
        }

        return $eligiblePasses
            ->sortBy([
                ['purchased_at', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    /**
     * @return Collection<int, CustomerClassPass>
     */
    private function activeCustomerClassPasses(int $accountId, int $customerId, bool $lockForUpdate = false): Collection
    {
        $query = CustomerClassPass::query()
            ->where('account_id', $accountId)
            ->where('customer_id', $customerId)
            ->active()
            ->with(['classPassPlan.classTypes', 'classPassPlan.trainerTypes', 'classPassPlan.rooms'])
            ->orderBy('purchased_at')
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function predictedReservationStatus(ClassBooking $classBooking): CustomerClassPassReservationStatus
    {
        return match ($classBooking->status) {
            ClassBookingStatus::Attended, ClassBookingStatus::NoShow => CustomerClassPassReservationStatus::Used,
            ClassBookingStatus::Booked => $classBooking->scheduledClass?->ends_at?->lessThan(
                now()->subMinutes(ScheduledClass::STUDIO_CANCELLATION_GRACE_MINUTES),
            )
                ? CustomerClassPassReservationStatus::Used
                : CustomerClassPassReservationStatus::Reserved,
            default => throw new LogicException('Cancelled bookings cannot be normalized into an active class-pass reservation.'),
        };
    }

    private function usedAt(ClassBooking $classBooking): Carbon
    {
        if ($classBooking->status === ClassBookingStatus::Attended) {
            return $classBooking->attended_at ?? now();
        }

        return $classBooking->scheduledClass?->starts_at ?? now();
    }
}
