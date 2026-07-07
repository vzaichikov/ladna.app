<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\ScheduledClass;
use App\Support\UnreservedClassPassBookingIssues;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconcileUnreservedCustomerBookingsForIssuedClassPass
{
    public function __construct(
        private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
        private readonly UnreservedClassPassBookingIssues $unreservedClassPassBookingIssues,
    ) {}

    public function execute(CustomerClassPass $customerClassPass): int
    {
        return $this->executeForAccountCustomer((int) $customerClassPass->account_id, (int) $customerClassPass->customer_id);
    }

    public function executeForCustomer(Customer $customer): int
    {
        return $this->executeForAccountCustomer((int) $customer->account_id, (int) $customer->id);
    }

    /**
     * @return array{
     *     passes: array<int, array{pass: CustomerClassPass, reserved_count: int, used_count: int, bookings: array<int, array{booking: ClassBooking, reservation_status: CustomerClassPassReservationStatus}>}>,
     *     totals: array{reserved: int, used: int},
     *     has_changes: bool
     * }
     */
    public function previewForCustomer(Customer $customer): array
    {
        $customerClassPasses = $this->activeCustomerClassPasses((int) $customer->account_id, (int) $customer->id);
        $summaries = $customerClassPasses
            ->mapWithKeys(fn (CustomerClassPass $customerClassPass): array => [
                $customerClassPass->id => [
                    'pass' => $customerClassPass,
                    'reserved_count' => 0,
                    'used_count' => 0,
                    'bookings' => [],
                ],
            ])
            ->all();
        $simulatedCounters = $customerClassPasses
            ->mapWithKeys(fn (CustomerClassPass $customerClassPass): array => [
                $customerClassPass->id => [
                    'reserved' => (int) $customerClassPass->reserved_sessions_count,
                    'used' => (int) $customerClassPass->used_sessions_count,
                ],
            ])
            ->all();

        foreach ($this->candidateBookings((int) $customer->account_id, (int) $customer->id) as $classBooking) {
            if (! $classBooking->scheduledClass) {
                continue;
            }

            foreach ($customerClassPasses as $customerClassPass) {
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
            fn (array $summary): bool => $summary['reserved_count'] > 0 || $summary['used_count'] > 0,
        ));
        $reservedTotal = array_sum(array_column($passes, 'reserved_count'));
        $usedTotal = array_sum(array_column($passes, 'used_count'));

        return [
            'passes' => $passes,
            'totals' => [
                'reserved' => $reservedTotal,
                'used' => $usedTotal,
            ],
            'has_changes' => $reservedTotal > 0 || $usedTotal > 0,
        ];
    }

    private function executeForAccountCustomer(int $accountId, int $customerId): int
    {
        return DB::transaction(function () use ($accountId, $customerId): int {
            $customerClassPasses = $this->activeCustomerClassPasses($accountId, $customerId, lockForUpdate: true);

            if ($customerClassPasses->isEmpty()) {
                return 0;
            }

            $customerClassPassIds = $customerClassPasses->modelKeys();
            $classBookings = $this->ledgerCandidateBookings($accountId, $customerId, $customerClassPassIds);

            if ($classBookings->isEmpty()) {
                return 0;
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
                    $reservation->update($attributes);
                } else {
                    $customerClassPass->reservations()->create($attributes);
                }

                if ($reservationStatus === CustomerClassPassReservationStatus::Used) {
                    $customerClassPass->used_sessions_count++;
                } else {
                    $customerClassPass->reserved_sessions_count++;
                }

                $reconciledCount++;
            }

            $customerClassPasses
                ->each(fn (CustomerClassPass $pass): CustomerClassPass => $this->normalizeCustomerClassPasses->forPass($pass));

            return $reconciledCount;
        });
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
            ->whereIn("{$classBookingTable}.status", array_map(
                fn (ClassBookingStatus $status): string => $status->value,
                ClassBookingStatus::cases(),
            ))
            ->where("{$classBookingTable}.skip_class_pass_reservation", false)
            ->where("{$scheduledClassTable}.account_id", $accountId)
            ->where("{$scheduledClassTable}.status", 'scheduled')
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
            ->with(['scheduledClass.classType', 'scheduledClass.trainer', 'scheduledClass.room', 'customer'])
            ->get();
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
        if ($classBooking->status !== ClassBookingStatus::Booked) {
            return CustomerClassPassReservationStatus::Used;
        }

        $endsAt = $classBooking->scheduledClass?->ends_at;

        if ($endsAt && $endsAt->lessThan(now()->subMinutes(ScheduledClass::STUDIO_CANCELLATION_GRACE_MINUTES))) {
            return CustomerClassPassReservationStatus::Used;
        }

        return CustomerClassPassReservationStatus::Reserved;
    }

    private function usedAt(ClassBooking $classBooking): Carbon
    {
        if ($classBooking->status === ClassBookingStatus::Attended) {
            return $classBooking->attended_at ?? now();
        }

        return $classBooking->scheduledClass?->starts_at ?? now();
    }
}
