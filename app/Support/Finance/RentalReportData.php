<?php

namespace App\Support\Finance;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\CustomerClassPassReservation;
use App\Models\ScheduledClass;
use App\Support\Salary\ClassPassSessionValueResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RentalReportData
{
    public function __construct(private readonly ClassPassSessionValueResolver $sessionValueResolver) {}

    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null}  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, array<string, int>>}
     */
    public function forAccount(
        Account $account,
        array $filters,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): array {
        $rentals = ScheduledClass::query()
            ->whereBelongsTo($account)
            ->where('status', '!=', ScheduledClassStatus::Cancelled->value)
            ->where('ends_at', '<=', now())
            ->whereBetween('starts_at', [$startsAt, $endsAt])
            ->whereHas('classType', fn (Builder $query): Builder => $query
                ->where('schedule_kind', ScheduleKind::RoomRental->value))
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query->where('location_id', $locationId))
            ->with([
                'location',
                'room',
                'classType',
                'classBookings' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->whereIn('status', [
                        ClassBookingStatus::Booked->value,
                        ClassBookingStatus::Attended->value,
                        ClassBookingStatus::NoShow->value,
                    ])
                    ->with([
                        'customer',
                        'classPassReservation.customerClassPass',
                        'manualCashPayment.refunds',
                    ])
                    ->orderBy('id'),
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
        $reservations = $rentals
            ->flatMap(fn (ScheduledClass $rental): Collection => $rental->classBookings)
            ->map(fn (ClassBooking $booking): ?CustomerClassPassReservation => $booking->activeClassPassReservation())
            ->filter()
            ->values();
        $positions = $this->sessionValueResolver->positionsFor($account, $reservations);
        $rows = $rentals
            ->flatMap(fn (ScheduledClass $rental): Collection => $rental->classBookings
                ->map(fn (ClassBooking $booking): array => $this->row($rental, $booking, $positions)))
            ->sortByDesc(fn (array $row): mixed => $row['starts_at'])
            ->values();

        return [
            'rows' => $rows,
            'totals' => [
                'accrued' => $this->sumMaps($rows->pluck('accrued_by_currency')),
                'paid' => $this->sumMaps($rows->pluck('paid_by_currency')),
                'refunded' => $this->sumMaps($rows->pluck('refunded_by_currency')),
                'debt' => $this->sumMaps($rows->pluck('debt_by_currency')),
            ],
        ];
    }

    /**
     * @param  Collection<int, int>  $positions
     * @return array<string, mixed>
     */
    private function row(ScheduledClass $rental, ClassBooking $booking, Collection $positions): array
    {
        $accrued = [];
        $paid = [];
        $refunded = [];
        $reservation = $booking->activeClassPassReservation();

        if ($reservation) {
            $reservation->loadMissing('customerClassPass');
            $customerClassPass = $reservation->customerClassPass;
            $currency = strtoupper((string) ($customerClassPass?->currency ?? 'UAH'));
            $accruedAmount = $this->sessionValueResolver->amountCents($reservation, $positions);
            $position = (int) $positions->get($reservation->id, 0);

            if ($accruedAmount !== null) {
                $accrued[$currency] = ($accrued[$currency] ?? 0) + $accruedAmount;
            }

            if ($customerClassPass) {
                $paidAmount = $this->allocatedAmount(
                    $customerClassPass->paidAmountCents(),
                    (int) $customerClassPass->sessions_count,
                    $position,
                );
                $paid[$currency] = ($paid[$currency] ?? 0) + $paidAmount;
            }
        }

        $directPayment = $booking->manualCashPayment;

        if ($directPayment?->status === CustomerPurchaseStatus::PaymentPaid) {
            $currency = strtoupper((string) $directPayment->currency);
            $paid[$currency] = ($paid[$currency] ?? 0) + (int) $directPayment->amount_cents;
            $accrued[$currency] = ($accrued[$currency] ?? 0) + (int) $directPayment->amount_cents;
            $refunded[$currency] = ($refunded[$currency] ?? 0) + (int) $directPayment->refunds->sum('amount_cents');
        }

        $debt = collect(array_unique([...array_keys($accrued), ...array_keys($paid), ...array_keys($refunded)]))
            ->mapWithKeys(fn (string $currency): array => [
                $currency => max(
                    0,
                    (int) ($accrued[$currency] ?? 0)
                        - max(0, (int) ($paid[$currency] ?? 0) - (int) ($refunded[$currency] ?? 0)),
                ),
            ])
            ->sortKeys()
            ->all();

        return [
            'scheduled_class' => $rental,
            'booking' => $booking,
            'starts_at' => $rental->starts_at,
            'location' => $rental->location,
            'room' => $rental->room,
            'customer' => $booking->customer,
            'duration_minutes' => $rental->durationMinutes(),
            'accrued_by_currency' => collect($accrued)->sortKeys()->all(),
            'paid_by_currency' => collect($paid)->sortKeys()->all(),
            'refunded_by_currency' => collect($refunded)->filter()->sortKeys()->all(),
            'debt_by_currency' => $debt,
            'status' => $this->status($accrued, $paid, $refunded, $debt),
        ];
    }

    private function allocatedAmount(int $amountCents, int $sessionsCount, int $position): int
    {
        if ($sessionsCount < 1) {
            return 0;
        }

        $amountCents = max(0, $amountCents);
        $baseAmount = intdiv($amountCents, $sessionsCount);
        $remainder = $amountCents % $sessionsCount;

        return $baseAmount + ($position > 0 && $position <= $remainder ? 1 : 0);
    }

    /**
     * @param  array<string, int>  $accrued
     * @param  array<string, int>  $paid
     * @param  array<string, int>  $refunded
     * @param  array<string, int>  $debt
     */
    private function status(array $accrued, array $paid, array $refunded, array $debt): string
    {
        if (array_sum($refunded) > 0 && array_sum($paid) - array_sum($refunded) <= 0) {
            return 'refunded';
        }

        if (array_sum($debt) === 0 && array_sum($accrued) > 0) {
            return 'paid';
        }

        if (array_sum($paid) > 0) {
            return 'partially_paid';
        }

        return 'unpaid';
    }

    /**
     * @param  Collection<int, array<string, int>>  $maps
     * @return array<string, int>
     */
    private function sumMaps(Collection $maps): array
    {
        return $maps
            ->flatMap(fn (array $amounts): Collection => collect($amounts)
                ->map(fn (int $amount, string $currency): array => compact('currency', 'amount'))
                ->values())
            ->groupBy('currency')
            ->map(fn (Collection $amounts): int => (int) $amounts->sum('amount'))
            ->sortKeys()
            ->all();
    }
}
