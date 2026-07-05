<?php

namespace App\Support\Reports;

use App\Models\Account;
use App\Support\DateTimePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UnknownPresenceReportData
{
    /**
     * @param  array{date?: string|null, location_id?: int|null, room_id?: int|null}  $filters
     */
    public function forAccount(Account $account, int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($filters);
        $dateRange = $filters['date'] === null ? null : $this->databaseDateRange($account, $filters['date']);

        return $account->unknownPresenceIntervals()
            ->with([
                'location:id,account_id,name,timezone',
                'room:id,account_id,location_id,name',
                'samples' => fn ($query) => $query
                    ->select([
                        'id',
                        'account_id',
                        'unknown_presence_interval_id',
                        'captured_at',
                        'status',
                        'original_image_path',
                        'detected_count',
                        'average_confidence',
                    ])
                    ->whereNotNull('original_image_path')
                    ->orderBy('captured_at'),
            ])
            ->when($dateRange !== null, fn ($query) => $query
                ->where('started_at', '<=', $dateRange[1])
                ->where('ended_at', '>=', $dateRange[0]))
            ->when($filters['location_id'] !== null, fn ($query) => $query->where('location_id', $filters['location_id']))
            ->when($filters['room_id'] !== null, fn ($query) => $query->where('room_id', $filters['room_id']))
            ->orderByDesc('started_at')
            ->paginate($perPage)
            ->withQueryString();
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
     * @param  array{date?: string|null, location_id?: int|null, room_id?: int|null}  $filters
     * @return array{date: string|null, location_id: int|null, room_id: int|null}
     */
    private function normalizeFilters(array $filters): array
    {
        return [
            'date' => filled($filters['date'] ?? null) ? (string) $filters['date'] : null,
            'location_id' => filled($filters['location_id'] ?? null) ? (int) $filters['location_id'] : null,
            'room_id' => filled($filters['room_id'] ?? null) ? (int) $filters['room_id'] : null,
        ];
    }
}
