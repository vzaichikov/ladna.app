<?php

namespace App\Http\Controllers;

use App\Enums\ClassBookingStatus;
use App\Models\Account;
use Carbon\CarbonImmutable;
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
        $selectedDate = $this->selectedDate($request, $timezone);
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
        $selectedLocationIds = $this->selectedIds($request, 'locations', $filterLocations->pluck('id')->all());
        $selectedRoomIds = $this->selectedIds($request, 'rooms', $filterRooms->pluck('id')->all());

        $scheduledClasses = $account->scheduledClasses()
            ->with([
                'location',
                'room',
                'classType.activityDirection',
                'trainer',
                'scheduleSeries',
                'activeCancellation.effects',
                'classBookings.customer',
                'classBookings.manualCashPayment',
                'classBookings.classPassReservation.customerClassPass.classPassPlan',
            ])
            ->whereBetween('starts_at', [
                $selectedDate->timezone(config('app.timezone')),
                $selectedDate->endOfDay()->timezone(config('app.timezone')),
            ])
            ->where('ends_at', '<=', now())
            ->when($selectedLocationIds !== [], fn ($query) => $query->whereIn('location_id', $selectedLocationIds))
            ->when($selectedRoomIds !== [], fn ($query) => $query->whereIn('room_id', $selectedRoomIds))
            ->orderBy('starts_at')
            ->get();

        return view('scheduled-classes.history', [
            'account' => $account,
            'scheduledClassDays' => $this->groupByDisplayDate($scheduledClasses),
            'filterLocations' => $filterLocations,
            'filterRooms' => $filterRooms,
            'selectedDate' => $selectedDate->toDateString(),
            'selectedLocationIds' => $selectedLocationIds,
            'selectedRoomIds' => $selectedRoomIds,
            'customerSearchUrl' => route('dashboard.accounts.customers.search', $account),
            'bookingStatuses' => ClassBookingStatus::cases(),
        ]);
    }

    private function selectedDate(Request $request, string $timezone): CarbonImmutable
    {
        $date = (string) $request->query('date', '');

        if ($date !== '' && CarbonImmutable::hasFormat($date, 'Y-m-d')) {
            try {
                $selectedDate = CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone);

                if ($selectedDate instanceof CarbonImmutable) {
                    return $selectedDate->startOfDay();
                }
            } catch (Throwable) {
                return CarbonImmutable::now($timezone)->subDay()->startOfDay();
            }
        }

        return CarbonImmutable::now($timezone)->subDay()->startOfDay();
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
