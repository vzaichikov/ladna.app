<?php

namespace App\Http\Controllers;

use App\Enums\ClassBookingStatus;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduledClassController extends Controller
{
    public function __invoke(Request $request, Account $account): View
    {
        $this->authorize('view', $account);

        $timezone = $account->timezone ?? config('app.timezone');
        $activeTab = $this->activeTab((string) $request->query('tab', 'today'));
        [$startsAt, $endsAt] = $this->tabRange($activeTab, $timezone);
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
            ->with(['location', 'room', 'classType.activityDirection', 'trainer', 'scheduleSeries', 'classBookings.customer'])
            ->whereBetween('starts_at', [
                $startsAt->timezone(config('app.timezone')),
                $endsAt->timezone(config('app.timezone')),
            ])
            ->when($selectedLocationIds !== [], fn ($query) => $query->whereIn('location_id', $selectedLocationIds))
            ->when($selectedRoomIds !== [], fn ($query) => $query->whereIn('room_id', $selectedRoomIds))
            ->orderBy('starts_at')
            ->get();

        return view('scheduled-classes.index', [
            'account' => $account,
            'activeTab' => $activeTab,
            'tabs' => $this->tabs(),
            'scheduledClassDays' => $scheduledClasses->groupBy(fn ($scheduledClass): string => $scheduledClass->starts_at->copy()
                ->timezone($scheduledClass->displayTimezone())
                ->toDateString()),
            'filterLocations' => $filterLocations,
            'filterRooms' => $filterRooms,
            'selectedLocationIds' => $selectedLocationIds,
            'selectedRoomIds' => $selectedRoomIds,
            'customerSearchUrl' => route('dashboard.accounts.customers.search', $account),
            'bookingStatuses' => ClassBookingStatus::cases(),
        ]);
    }

    private function activeTab(string $tab): string
    {
        return array_key_exists($tab, $this->tabs()) ? $tab : 'today';
    }

    /**
     * @return array<string, string>
     */
    private function tabs(): array
    {
        return [
            'today' => __('app.today'),
            'tomorrow' => __('app.tomorrow'),
            'this_week' => __('app.this_week'),
            'next_week' => __('app.next_week'),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function tabRange(string $tab, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return match ($tab) {
            'tomorrow' => [$today->addDay(), $today->addDay()->endOfDay()],
            'this_week' => [$today, $today->endOfWeek(CarbonInterface::SUNDAY)],
            'next_week' => [$today->addWeek()->startOfWeek(CarbonInterface::MONDAY), $today->addWeek()->endOfWeek(CarbonInterface::SUNDAY)],
            default => [$today, $today->endOfDay()],
        };
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
}
