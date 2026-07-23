<?php

namespace App\Support\Reports;

use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\CustomerClassPassReservation;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

class TrainerReportData
{
    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null, booking_statuses: array<int, string>}  $filters
     * @return Collection<int, array{trainer: Trainer, classes_count: int, private_lessons_count: int, group_people_count: int, private_people_count: int}>
     */
    public function forAccount(Account $account, array $filters): Collection
    {
        [$startsAt, $endsAt] = $this->databaseRange($account, $filters['date_from'], $filters['date_to']);

        $classCounts = $this->classCounts($account, $startsAt, $endsAt, $filters);
        $groupPeopleCounts = $this->groupPeopleCounts($account, $startsAt, $endsAt, $filters);

        return $account->trainers()
            ->with('trainerType')
            ->orderBy('name')
            ->get()
            ->map(function (Trainer $trainer) use ($classCounts, $groupPeopleCounts): array {
                $counts = $classCounts->get($trainer->id);

                return [
                    'trainer' => $trainer,
                    'classes_count' => (int) ($counts?->classes_count ?? 0),
                    'private_lessons_count' => (int) ($counts?->private_lessons_count ?? 0),
                    'group_people_count' => (int) ($groupPeopleCounts->get($trainer->id) ?? 0),
                    'private_people_count' => (int) ($counts?->private_people_count ?? 0),
                ];
            });
    }

    /**
     * @param  Collection<int, array{trainer: Trainer, classes_count: int, private_lessons_count: int, group_people_count: int, private_people_count: int}>  $rows
     * @return array{classes_count: int, private_lessons_count: int, group_people_count: int, private_people_count: int}
     */
    public function totals(Collection $rows): array
    {
        return [
            'classes_count' => (int) $rows->sum('classes_count'),
            'private_lessons_count' => (int) $rows->sum('private_lessons_count'),
            'group_people_count' => (int) $rows->sum('group_people_count'),
            'private_people_count' => (int) $rows->sum('private_people_count'),
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null, booking_statuses: array<int, string>}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function privateLessons(Account $account, Trainer $trainer, array $filters, bool $includeFinancials): LengthAwarePaginator
    {
        [$startsAt, $endsAt] = $this->databaseRange($account, $filters['date_from'], $filters['date_to']);
        $paginator = $this->privateLessonQuery($account, $startsAt, $endsAt, $filters)
            ->where('trainer_id', $trainer->id)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();
        $lessons = $paginator->getCollection();
        $reservationPositions = $includeFinancials
            ? $this->reservationPositions($lessons)
            : collect();

        $paginator->setCollection($lessons->map(function (ScheduledClass $lesson) use ($includeFinancials, $reservationPositions): array {
            $booking = $lesson->classBookings->first();
            $reservation = $this->activeReservation($booking);
            $customerClassPass = $reservation?->customerClassPass;
            $startsAt = $lesson->starts_at->copy()->timezone($lesson->displayTimezone());
            $endsAt = $lesson->ends_at->copy()->timezone($lesson->displayTimezone());

            return [
                'scheduled_class' => $lesson,
                'date' => $startsAt->toDateString(),
                'time' => $startsAt->format('H:i').'–'.$endsAt->format('H:i'),
                'class_type' => $lesson->classType?->name ?? $lesson->title,
                'duration_minutes' => $lesson->durationMinutes(),
                'customer' => $booking?->customer,
                'booking_status' => $booking?->status?->value,
                'people_count' => max(0, (int) $lesson->capacity),
                'location' => $lesson->location,
                'room' => $lesson->room,
                'class_pass' => $customerClassPass,
                'amount_cents' => $includeFinancials ? $this->lessonAmountCents($lesson, $reservationPositions) : null,
                'currency' => $customerClassPass?->currency,
            ];
        }));

        return $paginator;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function databaseRange(Account $account, string $dateFrom, string $dateTo): array
    {
        $timezone = $account->timezone ?? config('app.timezone');

        $startsAt = CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom, $timezone)
            ->startOfDay()
            ->timezone(config('app.timezone'));
        $endsAt = CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, $timezone)
            ->endOfDay()
            ->timezone(config('app.timezone'));

        return [$startsAt, $endsAt];
    }

    /**
     * @param  array{location_id: int|null, booking_statuses: array<int, string>}  $filters
     * @return Collection<int, ScheduledClass>
     */
    private function classCounts(Account $account, CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $filters): Collection
    {
        $classesTable = (new ScheduledClass)->getTable();
        $classTypesTable = (new ClassType)->getTable();

        return $this->qualifyingClassesQuery($account, $startsAt, $endsAt, $filters)
            ->select($classesTable.'.trainer_id')
            ->selectRaw('count(*) as classes_count')
            ->selectRaw(
                "sum(case when {$classTypesTable}.schedule_kind = ? then 1 else 0 end) as private_lessons_count",
                [ScheduleKind::PrivateLesson->value],
            )
            ->selectRaw(
                "sum(case when {$classTypesTable}.schedule_kind = ? then coalesce({$classesTable}.capacity, 0) else 0 end) as private_people_count",
                [ScheduleKind::PrivateLesson->value],
            )
            ->leftJoin($classTypesTable, function (JoinClause $join) use ($classesTable, $classTypesTable): void {
                $join
                    ->on($classTypesTable.'.id', '=', $classesTable.'.class_type_id')
                    ->on($classTypesTable.'.account_id', '=', $classesTable.'.account_id');
            })
            ->groupBy($classesTable.'.trainer_id')
            ->get()
            ->keyBy('trainer_id');
    }

    /**
     * @param  array{location_id: int|null, booking_statuses: array<int, string>}  $filters
     * @return Collection<int, int>
     */
    private function groupPeopleCounts(Account $account, CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $filters): Collection
    {
        $classesTable = (new ScheduledClass)->getTable();
        $bookingsTable = (new ClassBooking)->getTable();
        $classTypesTable = (new ClassType)->getTable();

        return ClassBooking::query()
            ->select($classesTable.'.trainer_id')
            ->selectRaw('count(*) as people_count')
            ->join($classesTable, $classesTable.'.id', '=', $bookingsTable.'.scheduled_class_id')
            ->join($classTypesTable, $classTypesTable.'.id', '=', $classesTable.'.class_type_id')
            ->where($bookingsTable.'.account_id', $account->id)
            ->whereNull($bookingsTable.'.corrected_removed_at')
            ->whereIn($bookingsTable.'.status', $filters['booking_statuses'])
            ->where($classesTable.'.account_id', $account->id)
            ->where($classesTable.'.status', '!=', ScheduledClassStatus::Cancelled->value)
            ->where($classesTable.'.ends_at', '<=', now())
            ->whereBetween($classesTable.'.starts_at', [$startsAt, $endsAt])
            ->where($classTypesTable.'.schedule_kind', ScheduleKind::GroupClass->value)
            ->when($filters['location_id'] !== null, fn (Builder $query) => $query->where($classesTable.'.location_id', $filters['location_id']))
            ->groupBy($classesTable.'.trainer_id')
            ->pluck('people_count', $classesTable.'.trainer_id');
    }

    /**
     * @param  array{location_id: int|null, booking_statuses: array<int, string>}  $filters
     * @return Builder<ScheduledClass>
     */
    private function qualifyingClassesQuery(Account $account, CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $filters): Builder
    {
        $classesTable = (new ScheduledClass)->getTable();

        return ScheduledClass::query()
            ->where($classesTable.'.account_id', $account->id)
            ->where($classesTable.'.status', '!=', ScheduledClassStatus::Cancelled->value)
            ->where($classesTable.'.ends_at', '<=', now())
            ->whereBetween($classesTable.'.starts_at', [$startsAt, $endsAt])
            ->whereHas('classType', fn (Builder $query) => $query
                ->whereIn('schedule_kind', ScheduleKindRegistry::trainerReportableValues()))
            ->when($filters['location_id'] !== null, fn (Builder $query) => $query->where($classesTable.'.location_id', $filters['location_id']))
            ->whereHas('classBookings', fn (Builder $query) => $query
                ->notCorrectedRemoved()
                ->whereIn('status', $filters['booking_statuses']));
    }

    /**
     * @param  array{location_id: int|null, booking_statuses: array<int, string>}  $filters
     * @return Builder<ScheduledClass>
     */
    private function privateLessonQuery(Account $account, CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $filters): Builder
    {
        return $this->qualifyingClassesQuery($account, $startsAt, $endsAt, $filters)
            ->whereHas('classType', fn (Builder $query) => $query->where('schedule_kind', ScheduleKind::PrivateLesson->value))
            ->with([
                'classType',
                'location',
                'room',
                'classBookings' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->whereIn('status', $filters['booking_statuses'])
                    ->orderBy('id')
                    ->with(['customer', 'classPassReservation.customerClassPass']),
            ]);
    }

    /**
     * @param  Collection<int, ScheduledClass>  $lessons
     * @return Collection<int, int>
     */
    private function reservationPositions(Collection $lessons): Collection
    {
        $customerClassPassIds = $lessons
            ->map(fn (ScheduledClass $lesson): ?CustomerClassPassReservation => $this->activeReservation($lesson->classBookings->first()))
            ->filter()
            ->pluck('customer_class_pass_id')
            ->unique()
            ->values();

        if ($customerClassPassIds->isEmpty()) {
            return collect();
        }

        return CustomerClassPassReservation::query()
            ->whereIn('customer_class_pass_id', $customerClassPassIds)
            ->whereIn('status', [
                CustomerClassPassReservationStatus::Reserved->value,
                CustomerClassPassReservationStatus::Used->value,
            ])
            ->orderBy('customer_class_pass_id')
            ->orderBy('reserved_at')
            ->orderBy('id')
            ->get(['id', 'customer_class_pass_id', 'reserved_at'])
            ->groupBy('customer_class_pass_id')
            ->reduce(function (Collection $positions, Collection $reservations): Collection {
                $reservations->values()->each(function (CustomerClassPassReservation $reservation, int $index) use ($positions): void {
                    $positions->put($reservation->id, $index + 1);
                });

                return $positions;
            }, collect());
    }

    /**
     * @param  Collection<int, int>  $reservationPositions
     */
    private function lessonAmountCents(ScheduledClass $lesson, Collection $reservationPositions): ?int
    {
        $reservation = $this->activeReservation($lesson->classBookings->first());
        $customerClassPass = $reservation?->customerClassPass;
        $sessionsCount = (int) ($customerClassPass?->sessions_count ?? 0);

        if (! $reservation || ! $customerClassPass || $sessionsCount < 1) {
            return null;
        }

        $priceCents = max(0, (int) $customerClassPass->price_cents);
        $baseAmount = intdiv($priceCents, $sessionsCount);
        $remainder = $priceCents % $sessionsCount;
        $position = (int) $reservationPositions->get($reservation->id, 0);

        return $baseAmount + ($position > 0 && $position <= $remainder ? 1 : 0);
    }

    private function activeReservation(?ClassBooking $booking): ?CustomerClassPassReservation
    {
        $reservation = $booking?->classPassReservation;

        if (! $reservation || ! in_array($reservation->status, [
            CustomerClassPassReservationStatus::Reserved,
            CustomerClassPassReservationStatus::Used,
        ], true)) {
            return null;
        }

        return $reservation;
    }
}
