<?php

namespace App\Support\Reports;

use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class TrainerReportData
{
    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null, booking_statuses: array<int, string>}  $filters
     * @return Collection<int, array{trainer: Trainer, classes_count: int, people_count: int}>
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
            ->map(fn (Trainer $trainer): array => [
                'trainer' => $trainer,
                'classes_count' => (int) ($classCounts->get($trainer->id) ?? 0),
                'people_count' => (int) ($peopleCounts->get($trainer->id) ?? 0),
            ]);
    }

    /**
     * @param  Collection<int, array{trainer: Trainer, classes_count: int, people_count: int}>  $rows
     * @return array{classes_count: int, people_count: int}
     */
    public function totals(Collection $rows): array
    {
        return [
            'classes_count' => (int) $rows->sum('classes_count'),
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
     * @return Collection<int, int>
     */
    private function classCounts(Account $account, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $locationId): Collection
    {
        return $account->scheduledClasses()
            ->select('trainer_id')
            ->selectRaw('count(*) as classes_count')
            ->where('status', '!=', ScheduledClassStatus::Cancelled->value)
            ->whereBetween('starts_at', [$startsAt, $endsAt])
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->groupBy('trainer_id')
            ->pluck('classes_count', 'trainer_id');
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
