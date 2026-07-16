<?php

namespace App\Support\Reports;

use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

class TrainerReportData
{
    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null, booking_statuses: array<int, string>}  $filters
     * @return Collection<int, array{trainer: Trainer, classes_count: int, private_lessons_count: int, people_count: int}>
     */
    public function forAccount(Account $account, array $filters): Collection
    {
        [$startsAt, $endsAt] = $this->databaseRange($account, $filters['date_from'], $filters['date_to']);

        $classCounts = $this->classCounts($account, $startsAt, $endsAt, $filters['location_id']);
        $peopleCounts = $this->peopleCounts($account, $startsAt, $endsAt, $filters);

        return $account->trainers()
            ->with('trainerType')
            ->orderBy('name')
            ->get()
            ->map(function (Trainer $trainer) use ($classCounts, $peopleCounts): array {
                $counts = $classCounts->get($trainer->id);

                return [
                    'trainer' => $trainer,
                    'classes_count' => (int) ($counts?->classes_count ?? 0),
                    'private_lessons_count' => (int) ($counts?->private_lessons_count ?? 0),
                    'people_count' => (int) ($peopleCounts->get($trainer->id) ?? 0),
                ];
            });
    }

    /**
     * @param  Collection<int, array{trainer: Trainer, classes_count: int, private_lessons_count: int, people_count: int}>  $rows
     * @return array{classes_count: int, private_lessons_count: int, people_count: int}
     */
    public function totals(Collection $rows): array
    {
        return [
            'classes_count' => (int) $rows->sum('classes_count'),
            'private_lessons_count' => (int) $rows->sum('private_lessons_count'),
            'people_count' => (int) $rows->sum('people_count'),
        ];
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
     * @return Collection<int, ScheduledClass>
     */
    private function classCounts(Account $account, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $locationId): Collection
    {
        $classesTable = (new ScheduledClass)->getTable();
        $classTypesTable = (new ClassType)->getTable();

        return $account->scheduledClasses()
            ->select($classesTable.'.trainer_id')
            ->selectRaw('count(*) as classes_count')
            ->selectRaw(
                "sum(case when {$classTypesTable}.schedule_kind = ? then 1 else 0 end) as private_lessons_count",
                [ScheduleKind::PrivateLesson->value],
            )
            ->leftJoin($classTypesTable, function (JoinClause $join) use ($classesTable, $classTypesTable): void {
                $join
                    ->on($classTypesTable.'.id', '=', $classesTable.'.class_type_id')
                    ->on($classTypesTable.'.account_id', '=', $classesTable.'.account_id');
            })
            ->where($classesTable.'.status', '!=', ScheduledClassStatus::Cancelled->value)
            ->whereBetween($classesTable.'.starts_at', [$startsAt, $endsAt])
            ->when($locationId !== null, fn ($query) => $query->where($classesTable.'.location_id', $locationId))
            ->groupBy($classesTable.'.trainer_id')
            ->get()
            ->keyBy('trainer_id');
    }

    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null, booking_statuses: array<int, string>}  $filters
     * @return Collection<int, int>
     */
    private function peopleCounts(Account $account, CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $filters): Collection
    {
        $classesTable = (new ScheduledClass)->getTable();
        $bookingsTable = (new ClassBooking)->getTable();

        return ClassBooking::query()
            ->select($classesTable.'.trainer_id')
            ->selectRaw('count(*) as people_count')
            ->join($classesTable, $classesTable.'.id', '=', $bookingsTable.'.scheduled_class_id')
            ->where($bookingsTable.'.account_id', $account->id)
            ->where($classesTable.'.account_id', $account->id)
            ->where($classesTable.'.status', '!=', ScheduledClassStatus::Cancelled->value)
            ->whereBetween($classesTable.'.starts_at', [$startsAt, $endsAt])
            ->whereIn($bookingsTable.'.status', $filters['booking_statuses'])
            ->when($filters['location_id'] !== null, fn ($query) => $query->where($classesTable.'.location_id', $filters['location_id']))
            ->groupBy($classesTable.'.trainer_id')
            ->pluck('people_count', $classesTable.'.trainer_id');
    }
}
