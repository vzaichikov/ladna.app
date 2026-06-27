<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReconcileUnreservedCustomerBookingsForIssuedClassPass
{
    public function __construct(
        private readonly ReconcileCustomerClassPassForBooking $reconcileCustomerClassPassForBooking,
        private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
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
        $touchedPassIds = [];
        $reconciledCount = 0;

        foreach ($this->candidateBookingIds($accountId, $customerId) as $bookingId) {
            $classBooking = ClassBooking::query()
                ->with(['scheduledClass.classType', 'scheduledClass.trainer', 'scheduledClass.room', 'customer'])
                ->whereKey($bookingId)
                ->first();

            if (! $classBooking) {
                continue;
            }

            $reservation = $this->reconcileCustomerClassPassForBooking->execute($classBooking);

            if (! $reservation) {
                continue;
            }

            $touchedPassIds[$reservation->customer_class_pass_id] = true;
            $reconciledCount++;
        }

        CustomerClassPass::query()
            ->whereKey(array_keys($touchedPassIds))
            ->orderBy('id')
            ->get()
            ->each(fn (CustomerClassPass $pass): CustomerClassPass => $this->normalizeCustomerClassPasses->forPass($pass));

        return $reconciledCount;
    }

    /**
     * @return iterable<int, int>
     */
    private function candidateBookingIds(int $accountId, int $customerId): iterable
    {
        $classBookingTable = (new ClassBooking)->getTable();

        return $this->candidateBookingsQuery($accountId, $customerId)
            ->pluck("{$classBookingTable}.id");
    }

    /**
     * @return Collection<int, ClassBooking>
     */
    private function candidateBookings(int $accountId, int $customerId): Collection
    {
        return $this->candidateBookingsQuery($accountId, $customerId)
            ->with(['scheduledClass.classType', 'scheduledClass.trainer', 'scheduledClass.room', 'customer'])
            ->get();
    }

    /**
     * @return Collection<int, CustomerClassPass>
     */
    private function activeCustomerClassPasses(int $accountId, int $customerId): Collection
    {
        return CustomerClassPass::query()
            ->where('account_id', $accountId)
            ->where('customer_id', $customerId)
            ->active()
            ->with(['classPassPlan.classTypes', 'classPassPlan.trainerTypes', 'classPassPlan.rooms'])
            ->orderBy('purchased_at')
            ->orderBy('id')
            ->get();
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

    private function candidateBookingsQuery(int $accountId, int $customerId): Builder
    {
        $classBookingTable = (new ClassBooking)->getTable();
        $scheduledClassTable = (new ScheduledClass)->getTable();

        return ClassBooking::query()
            ->select("{$classBookingTable}.*")
            ->join($scheduledClassTable, "{$scheduledClassTable}.id", '=', "{$classBookingTable}.scheduled_class_id")
            ->where("{$classBookingTable}.account_id", $accountId)
            ->where("{$classBookingTable}.customer_id", $customerId)
            ->whereIn("{$classBookingTable}.status", array_map(
                fn (ClassBookingStatus $status): string => $status->value,
                ClassBookingStatus::cases(),
            ))
            ->where("{$scheduledClassTable}.account_id", $accountId)
            ->where("{$scheduledClassTable}.status", ScheduledClassStatus::Scheduled->value)
            ->whereDoesntHave('classPassReservation', fn ($query) => $query->whereIn('status', [
                CustomerClassPassReservationStatus::Reserved->value,
                CustomerClassPassReservationStatus::Used->value,
            ]))
            ->orderBy("{$scheduledClassTable}.starts_at")
            ->orderBy("{$classBookingTable}.id");
    }
}
