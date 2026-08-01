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
use App\Models\StudioExpense;
use App\Support\Salary\ClassPassSessionValueResolver;
use App\Support\Salary\TrainerSalaryCalculator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EarningsReportData
{
    public function __construct(
        private readonly ClassPassSessionValueResolver $sessionValueResolver,
        private readonly TrainerSalaryCalculator $salaryCalculator,
    ) {}

    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null}  $filters
     * @return array<string, mixed>
     */
    public function forAccount(
        Account $account,
        array $filters,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): array {
        $classes = ScheduledClass::query()
            ->whereBelongsTo($account)
            ->where('status', '!=', ScheduledClassStatus::Cancelled->value)
            ->where('ends_at', '<=', now())
            ->whereBetween('starts_at', [$startsAt, $endsAt])
            ->whereHas('classType', fn (Builder $query): Builder => $query->whereIn('schedule_kind', [
                ScheduleKind::GroupClass->value,
                ScheduleKind::PrivateLesson->value,
                ScheduleKind::RoomRental->value,
            ]))
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
                        'classPassReservation.customerClassPass',
                        'manualCashPayment',
                    ])
                    ->orderBy('id'),
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
        $reservations = $classes
            ->flatMap(fn (ScheduledClass $scheduledClass): Collection => $scheduledClass->classBookings)
            ->map(fn (ClassBooking $booking): ?CustomerClassPassReservation => $booking->activeClassPassReservation())
            ->filter()
            ->values();
        $positions = $this->sessionValueResolver->positionsFor($account, $reservations);
        $rows = $classes
            ->map(fn (ScheduledClass $scheduledClass): array => $this->classRow($scheduledClass, $positions))
            ->sortByDesc(fn (array $row): mixed => $row['starts_at'])
            ->values();
        $lessonRevenue = $this->sumMaps($rows
            ->where('kind', '!=', ScheduleKind::RoomRental->value)
            ->pluck('value_by_currency'));
        $rentalRevenue = $this->sumMaps($rows
            ->where('kind', ScheduleKind::RoomRental->value)
            ->pluck('value_by_currency'));
        $revenue = $this->mergeTotals($lessonRevenue, $rentalRevenue);
        $expenses = StudioExpense::query()
            ->whereBelongsTo($account)
            ->active()
            ->whereBetween('occurred_at', [$startsAt, $endsAt])
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query
                ->where('expense_location_id', $locationId))
            ->get(['amount_cents', 'currency']);
        $expenseTotals = $expenses
            ->groupBy(fn (StudioExpense $expense): string => strtoupper((string) $expense->currency))
            ->map(fn (Collection $currencyExpenses): int => (int) $currencyExpenses->sum('amount_cents'))
            ->sortKeys()
            ->all();
        $salary = $this->salaryAfterBoundary(
            $this->salaryCalculator->forAccount($account, $filters),
            $startsAt,
        );
        $salaryTotals = $salary['totals'];

        return [
            'rows' => $rows,
            'totals' => [
                'lesson_revenue' => $lessonRevenue,
                'rental_revenue' => $rentalRevenue,
                'revenue' => $revenue,
                'expenses' => $expenseTotals,
                'salary' => $salaryTotals,
                'earnings' => $this->subtractTotals(
                    $this->subtractTotals($revenue, $expenseTotals),
                    $salaryTotals,
                ),
            ],
            'salary_incomplete' => $salary['incomplete'],
        ];
    }

    /**
     * @param  Collection<int, int>  $positions
     * @return array<string, mixed>
     */
    private function classRow(ScheduledClass $scheduledClass, Collection $positions): array
    {
        $valueByCurrency = [];

        foreach ($scheduledClass->classBookings as $booking) {
            $reservation = $booking->activeClassPassReservation();

            if ($reservation) {
                $reservation->loadMissing('customerClassPass');
                $amountCents = $this->sessionValueResolver->amountCents($reservation, $positions);

                if ($amountCents !== null) {
                    $currency = strtoupper((string) ($reservation->customerClassPass?->currency ?? 'UAH'));
                    $valueByCurrency[$currency] = ($valueByCurrency[$currency] ?? 0) + $amountCents;
                }
            }

            $directPayment = $booking->manualCashPayment;

            if ($directPayment?->status === CustomerPurchaseStatus::PaymentPaid) {
                $currency = strtoupper((string) $directPayment->currency);
                $valueByCurrency[$currency] = ($valueByCurrency[$currency] ?? 0) + (int) $directPayment->amount_cents;
            }
        }

        return [
            'scheduled_class' => $scheduledClass,
            'starts_at' => $scheduledClass->starts_at,
            'kind' => $scheduledClass->classType?->schedule_kind?->value,
            'location' => $scheduledClass->location,
            'room' => $scheduledClass->room,
            'bookings_count' => $scheduledClass->classBookings->count(),
            'value_by_currency' => collect($valueByCurrency)->sortKeys()->all(),
        ];
    }

    /**
     * The salary calculator accepts calendar dates. A finance epoch can start
     * during a day, so completed-class entries before that exact boundary must
     * be removed from this financial report.
     *
     * @param  array<string, mixed>  $salary
     * @return array<string, mixed>
     */
    private function salaryAfterBoundary(array $salary, CarbonInterface $startsAt): array
    {
        $salary['trainers'] = $salary['trainers']->map(function (array $trainerSalary) use ($startsAt): array {
            $entries = $trainerSalary['entries']
                ->reject(fn (array $entry): bool => $entry['kind'] === 'class'
                    && $entry['scheduled_class']->starts_at->lessThan($startsAt))
                ->values();
            $trainerSalary['entries'] = $entries;
            $trainerSalary['amounts'] = $entries
                ->filter(fn (array $entry): bool => $entry['amount_cents'] !== null)
                ->groupBy('currency')
                ->map(fn (Collection $currencyEntries): int => (int) $currencyEntries->sum('amount_cents'))
                ->sortKeys()
                ->all();
            $trainerSalary['incomplete'] = ($trainerSalary['trainer']->is_active && ! $trainerSalary['current_model'])
                || $entries->contains(fn (array $entry): bool => $entry['amount_cents'] === null);

            return $trainerSalary;
        });
        $salary['totals'] = $salary['trainers']
            ->flatMap(fn (array $trainerSalary): Collection => collect($trainerSalary['amounts'])
                ->map(fn (int $amount, string $currency): array => compact('currency', 'amount'))
                ->values())
            ->groupBy('currency')
            ->map(fn (Collection $amounts): int => (int) $amounts->sum('amount'))
            ->sortKeys()
            ->all();
        $salary['incomplete'] = $salary['trainers']
            ->contains(fn (array $trainerSalary): bool => $trainerSalary['incomplete']);

        return $salary;
    }

    /**
     * @param  Collection<int, array<string, int>>  $maps
     * @return array<string, int>
     */
    private function sumMaps(Collection $maps): array
    {
        return $this->mergeTotals(...$maps->all());
    }

    /**
     * @param  array<string, int>  ...$totals
     * @return array<string, int>
     */
    private function mergeTotals(array ...$totals): array
    {
        return collect($totals)
            ->flatMap(fn (array $amounts): Collection => collect($amounts)
                ->map(fn (int $amount, string $currency): array => compact('currency', 'amount'))
                ->values())
            ->groupBy('currency')
            ->map(fn (Collection $amounts): int => (int) $amounts->sum('amount'))
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, int>  $minuend
     * @param  array<string, int>  $subtrahend
     * @return array<string, int>
     */
    private function subtractTotals(array $minuend, array $subtrahend): array
    {
        return collect(array_unique([...array_keys($minuend), ...array_keys($subtrahend)]))
            ->mapWithKeys(fn (string $currency): array => [
                $currency => (int) ($minuend[$currency] ?? 0) - (int) ($subtrahend[$currency] ?? 0),
            ])
            ->sortKeys()
            ->all();
    }
}
