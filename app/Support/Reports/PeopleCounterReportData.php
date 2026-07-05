<?php

namespace App\Support\Reports;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Support\DateTimePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PeopleCounterReportData
{
    /**
     * @param  array{date?: string|null, location_id?: int|null, room_id?: int|null, trainer_id?: int|null}  $filters
     */
    public function forAccount(Account $account, int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($filters);
        $dateRange = $filters['date'] === null ? null : $this->databaseDateRange($account, $filters['date']);

        return $account->scheduledClasses()
            ->with([
                'location:id,account_id,name,timezone',
                'room:id,account_id,location_id,name,rtsp_enabled,rtsp_url',
                'trainer:id,account_id,name',
                'classType:id,account_id,name',
                'peopleCount',
                'latestSuccessfulPeopleCounterSample',
            ])
            ->withCount([
                'classBookings as attended_bookings_count' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->where('status', ClassBookingStatus::Attended->value),
            ])
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('starts_at', '<=', $this->databaseNow($account))
            ->when($dateRange !== null, fn ($query) => $query->whereBetween('starts_at', $dateRange))
            ->when($filters['location_id'] !== null, fn ($query) => $query->where('location_id', $filters['location_id']))
            ->when($filters['room_id'] !== null, fn ($query) => $query->where('room_id', $filters['room_id']))
            ->when($filters['trainer_id'] !== null, fn ($query) => $query->where('trainer_id', $filters['trainer_id']))
            ->orderByDesc('starts_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function databaseNow(Account $account): CarbonImmutable
    {
        return CarbonImmutable::now(DateTimePresenter::accountTimezone($account))
            ->timezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function databaseDateRange(Account $account, string $date): array
    {
        $timezone = DateTimePresenter::accountTimezone($account);

        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)
                ->startOfDay()
                ->timezone((string) config('app.timezone', 'UTC')),
            CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)
                ->endOfDay()
                ->timezone((string) config('app.timezone', 'UTC')),
        ];
    }

    /**
     * @param  array{date?: string|null, location_id?: int|null, room_id?: int|null, trainer_id?: int|null}  $filters
     * @return array{date: string|null, location_id: int|null, room_id: int|null, trainer_id: int|null}
     */
    private function normalizeFilters(array $filters): array
    {
        return [
            'date' => filled($filters['date'] ?? null) ? (string) $filters['date'] : null,
            'location_id' => filled($filters['location_id'] ?? null) ? (int) $filters['location_id'] : null,
            'room_id' => filled($filters['room_id'] ?? null) ? (int) $filters['room_id'] : null,
            'trainer_id' => filled($filters['trainer_id'] ?? null) ? (int) $filters['trainer_id'] : null,
        ];
    }
}
