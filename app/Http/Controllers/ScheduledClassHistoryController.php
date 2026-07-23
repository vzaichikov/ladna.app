<?php

namespace App\Http\Controllers;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

class ScheduledClassHistoryController extends Controller
{
    public function __invoke(Request $request, Account $account): View
    {
        $this->authorize('view', $account);

        $timezone = $account->timezone ?? config('app.timezone');
        [$selectedDateFrom, $selectedDateTo] = $this->selectedDateRange($request, $timezone);
        $filterLocations = $account->locations()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);
        $filterRooms = $account->rooms()
            ->active()
            ->with('location:id,name')
            ->orderBy('location_id')
            ->orderBy('name')
            ->get(['id', 'location_id', 'name']);
        $filterTrainers = $account->trainers()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);
        $trainerOptions = $account->trainers()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
        $filterClassTypes = $account->classTypes()
            ->active()
            ->with('activityDirection:id,name')
            ->orderBy('schedule_kind')
            ->orderBy('name')
            ->get(['id', 'activity_direction_id', 'name', 'schedule_kind']);
        $filterScheduleKinds = array_keys(ScheduleKindRegistry::all());
        $allowedLocationIds = $account->locations()->pluck('id')->all();
        $allowedTrainerIds = $account->trainers()->pluck('id')->all();
        $selectedLocationIds = $this->selectedIds($request, 'locations', $allowedLocationIds);
        $selectedRoomIds = $this->selectedIds($request, 'rooms', $filterRooms->pluck('id')->all());
        $selectedTrainerIds = $this->selectedIds($request, 'trainers', $allowedTrainerIds);
        $selectedClassTypeIds = $this->selectedIds($request, 'class_types', $filterClassTypes->pluck('id')->all());
        $selectedScheduleKinds = $this->selectedScheduleKinds($request, $filterScheduleKinds);
        $withoutAttendance = $request->boolean('without_attendance');

        $scheduledClasses = $account->scheduledClasses()
            ->with([
                'location',
                'room',
                'classType.activityDirection',
                'trainer',
                'additionalTrainers',
                'trainerChanges',
                'scheduleSeries',
                'activeCancellation.effects',
                'classBookings' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->with(['customer', 'manualCashPayment', 'classPassReservation.customerClassPass.classPassPlan']),
            ])
            ->whereBetween('starts_at', [
                $selectedDateFrom->timezone(config('app.timezone')),
                $selectedDateTo->endOfDay()->timezone(config('app.timezone')),
            ])
            ->where('ends_at', '<=', now())
            ->when($selectedLocationIds !== [], fn ($query) => $query->whereIn('location_id', $selectedLocationIds))
            ->when($selectedRoomIds !== [], fn ($query) => $query->whereIn('room_id', $selectedRoomIds))
            ->when($selectedTrainerIds !== [], function ($query) use ($selectedTrainerIds): void {
                $query->where(function ($query) use ($selectedTrainerIds): void {
                    $query
                        ->whereIn('trainer_id', $selectedTrainerIds)
                        ->orWhereHas('additionalTrainers', fn ($query) => $query->whereKey($selectedTrainerIds));
                });
            })
            ->when($selectedClassTypeIds !== [], fn ($query) => $query->whereIn('class_type_id', $selectedClassTypeIds))
            ->when($selectedScheduleKinds !== [], function ($query) use ($selectedScheduleKinds): void {
                $query->whereHas('classType', fn (Builder $query) => $query->whereIn('schedule_kind', $selectedScheduleKinds));
            })
            ->when($withoutAttendance, function ($query): void {
                $query
                    ->where('status', ScheduledClassStatus::Scheduled->value)
                    ->whereHas('classType', fn (Builder $query) => $query
                        ->whereIn('schedule_kind', ScheduleKindRegistry::customerBookableValues()))
                    ->whereDoesntHave('classBookings', fn (Builder $query) => $query->notCorrectedRemoved());
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('scheduled-classes.history', [
            'account' => $account,
            'scheduledClasses' => $scheduledClasses,
            'scheduledClassDays' => $this->groupByDisplayDate($scheduledClasses->getCollection()),
            'filterLocations' => $filterLocations,
            'filterRooms' => $filterRooms,
            'filterTrainers' => $filterTrainers,
            'trainerOptions' => $trainerOptions,
            'filterClassTypes' => $filterClassTypes,
            'filterScheduleKinds' => $filterScheduleKinds,
            'selectedDateFrom' => $selectedDateFrom->toDateString(),
            'selectedDateTo' => $selectedDateTo->toDateString(),
            'selectedLocationIds' => $selectedLocationIds,
            'selectedRoomIds' => $selectedRoomIds,
            'selectedTrainerIds' => $selectedTrainerIds,
            'selectedClassTypeIds' => $selectedClassTypeIds,
            'selectedScheduleKinds' => $selectedScheduleKinds,
            'withoutAttendance' => $withoutAttendance,
            'customerSearchUrl' => route('dashboard.accounts.customers.search', $account),
            'bookingStatuses' => ClassBookingStatus::cases(),
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function selectedDateRange(Request $request, string $timezone): array
    {
        $defaultDate = CarbonImmutable::now($timezone)->subDay()->startOfDay();
        $legacyDate = $this->dateFromQuery($request, 'date', $timezone);
        $selectedDateFrom = $this->dateFromQuery($request, 'date_from', $timezone);
        $selectedDateTo = $this->dateFromQuery($request, 'date_to', $timezone);

        if (! $selectedDateFrom && ! $selectedDateTo && $legacyDate) {
            $selectedDateFrom = $legacyDate;
            $selectedDateTo = $legacyDate;
        }

        $selectedDateFrom ??= $defaultDate;
        $selectedDateTo ??= $selectedDateFrom;

        if ($selectedDateTo->lessThan($selectedDateFrom)) {
            $selectedDateTo = $selectedDateFrom;
        }

        return [$selectedDateFrom->startOfDay(), $selectedDateTo->startOfDay()];
    }

    private function dateFromQuery(Request $request, string $key, string $timezone): ?CarbonImmutable
    {
        $date = (string) $request->query($key, '');

        if ($date === '' || ! CarbonImmutable::hasFormat($date, 'Y-m-d')) {
            return null;
        }

        try {
            $selectedDate = CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone);

            return $selectedDate instanceof CarbonImmutable ? $selectedDate->startOfDay() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, int>  $allowedIds
     * @return array<int, int>
     */
    private function selectedIds(Request $request, string $key, array $allowedIds): array
    {
        $selectedIds = $request->query($key, []);

        if (! is_array($selectedIds)) {
            $selectedIds = [$selectedIds];
        }

        return collect($selectedIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => in_array($id, $allowedIds, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $allowedKinds
     * @return array<int, string>
     */
    private function selectedScheduleKinds(Request $request, array $allowedKinds): array
    {
        $selectedKinds = $request->query('schedule_kinds', []);

        if (! is_array($selectedKinds)) {
            $selectedKinds = [$selectedKinds];
        }

        return collect($selectedKinds)
            ->map(fn ($kind): string => (string) $kind)
            ->filter(fn (string $kind): bool => in_array($kind, $allowedKinds, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $scheduledClasses
     * @return Collection<string, Collection<int, mixed>>
     */
    private function groupByDisplayDate(Collection $scheduledClasses): Collection
    {
        return $scheduledClasses->groupBy(fn ($scheduledClass): string => $scheduledClass->starts_at->copy()
            ->timezone($scheduledClass->displayTimezone())
            ->toDateString());
    }
}
