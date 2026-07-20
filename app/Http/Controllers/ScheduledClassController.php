<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Models\Account;
use App\Models\Trainer;
use App\Support\QuickBookingOptions;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ScheduledClassController extends Controller
{
    private const ISO_MONDAY = 1;

    private const ISO_SUNDAY = 7;

    public function __invoke(Request $request, Account $account, QuickBookingOptions $quickBookingOptions): View
    {
        $this->authorize('view', $account);

        $timezone = $account->timezone ?? config('app.timezone');
        $activeTab = $this->activeTab((string) $request->query('tab', 'today'));
        [$tabStartsAt, $tabEndsAt] = $this->tabRange($activeTab, $timezone);
        $activeWeekday = $this->activeWeekday($request, $activeTab, $tabStartsAt, $tabEndsAt, $timezone);
        [$startsAt, $endsAt] = $activeWeekday
            ? $this->weekdayRange($activeTab, $activeWeekday, $timezone)
            : [$tabStartsAt, $tabEndsAt];
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
            ->get(['id', 'user_id', 'name']);
        $trainerOptions = $account->trainers()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
        $quickBookingData = $quickBookingOptions->forAccount($account);
        $manualClassOptions = $quickBookingData['options']
            ->filter(fn (array $option): bool => in_array($option['kind'], ScheduleKindRegistry::oneOffRecordKinds(), true))
            ->values();
        $selectedLocationIds = $this->selectedIds($request, 'locations', $filterLocations->pluck('id')->all());
        $selectedRoomIds = $this->selectedIds($request, 'rooms', $filterRooms->pluck('id')->all());
        $currentTrainer = $this->currentTrainerFor($account, $request, $filterTrainers);
        $showOnlyMyClasses = $currentTrainer !== null && $request->boolean('only_my_classes');
        $selectedTrainerIds = $showOnlyMyClasses
            ? []
            : $this->selectedIds($request, 'trainers', $filterTrainers->pluck('id')->all());
        $effectiveTrainerIds = $showOnlyMyClasses ? [$currentTrainer->id] : $selectedTrainerIds;
        $showPassed = $request->boolean('show_passed');
        $activeFilterQuery = $this->activeFilterQuery($selectedLocationIds, $selectedRoomIds, $selectedTrainerIds, $showOnlyMyClasses, $showPassed);

        $scheduledClasses = $account->scheduledClasses()
            ->with([
                'location',
                'room',
                'classType.activityDirection',
                'trainer',
                'trainerChanges',
                'scheduleSeries',
                'activeCancellation.effects',
                'classBookings' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->with(['customer', 'manualCashPayment', 'classPassReservation.customerClassPass.classPassPlan']),
            ])
            ->whereBetween('starts_at', [
                $startsAt->timezone(config('app.timezone')),
                $endsAt->timezone(config('app.timezone')),
            ])
            ->when(! $showPassed, fn ($query) => $query->where('ends_at', '>=', $this->pastCutoff($timezone)))
            ->when($selectedLocationIds !== [], fn ($query) => $query->whereIn('location_id', $selectedLocationIds))
            ->when($selectedRoomIds !== [], fn ($query) => $query->whereIn('room_id', $selectedRoomIds))
            ->when($effectiveTrainerIds !== [], fn ($query) => $query->whereIn('trainer_id', $effectiveTrainerIds))
            ->orderBy('starts_at')
            ->get();

        return view('scheduled-classes.index', [
            'account' => $account,
            'activeTab' => $activeTab,
            'activeWeekday' => $activeWeekday,
            'tabs' => $this->tabs(),
            'weekDayOptions' => $this->weekDayOptions($activeTab, $activeWeekday, $timezone),
            'scheduledClassDays' => $this->groupByDisplayDate($scheduledClasses),
            'filterLocations' => $filterLocations,
            'filterRooms' => $filterRooms,
            'filterTrainers' => $filterTrainers,
            'trainerOptions' => $trainerOptions,
            'quickBookingOptions' => $quickBookingData['options'],
            'manualClassOptions' => $manualClassOptions,
            'quickBookingLocations' => $quickBookingData['locations'],
            'quickBookingRooms' => $quickBookingData['rooms'],
            'quickBookingTrainers' => $quickBookingData['trainers'],
            'quickBookingActivityDirections' => $quickBookingData['activityDirections'],
            'selectedLocationIds' => $selectedLocationIds,
            'selectedRoomIds' => $selectedRoomIds,
            'selectedTrainerIds' => $selectedTrainerIds,
            'currentTrainer' => $currentTrainer,
            'showOnlyMyClasses' => $showOnlyMyClasses,
            'showPassed' => $showPassed,
            'activeFilterQuery' => $activeFilterQuery,
            'customerSearchUrl' => route('dashboard.accounts.customers.search', $account),
            'groupAvailabilityUrl' => route('dashboard.accounts.quick-bookings.group-availability', $account),
            'manualAvailabilityUrl' => route('dashboard.accounts.quick-bookings.manual-availability', $account),
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
            'week_after_next' => __('app.week_after_next'),
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
            'week_after_next' => [$today->addWeeks(2)->startOfWeek(CarbonInterface::MONDAY), $today->addWeeks(2)->endOfWeek(CarbonInterface::SUNDAY)],
            default => [$today, $today->endOfDay()],
        };
    }

    private function activeWeekday(Request $request, string $tab, CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $timezone): ?int
    {
        if (! $this->supportsWeekdayFilter($tab)) {
            return null;
        }

        $weekday = (int) $request->query('weekday', 0);

        if ($weekday < self::ISO_MONDAY || $weekday > self::ISO_SUNDAY) {
            return null;
        }

        $date = $this->dateForWeekday($tab, $weekday, $timezone);

        if ($date->lessThan($startsAt->startOfDay()) || $date->greaterThan($endsAt->startOfDay())) {
            return null;
        }

        return $weekday;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function weekdayRange(string $tab, int $weekday, string $timezone): array
    {
        $date = $this->dateForWeekday($tab, $weekday, $timezone);

        return [$date, $date->endOfDay()];
    }

    private function dateForWeekday(string $tab, int $weekday, string $timezone): CarbonImmutable
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $weekStart = match ($tab) {
            'next_week' => $today->addWeek()->startOfWeek(CarbonInterface::MONDAY),
            'week_after_next' => $today->addWeeks(2)->startOfWeek(CarbonInterface::MONDAY),
            default => $today->startOfWeek(CarbonInterface::MONDAY),
        };

        return $weekStart->addDays($weekday - self::ISO_MONDAY)->startOfDay();
    }

    private function supportsWeekdayFilter(string $tab): bool
    {
        return in_array($tab, ['this_week', 'next_week', 'week_after_next'], true);
    }

    /**
     * @return array<int, array{weekday: int, label: string, full_label: string, date_label: string, active: bool}>
     */
    private function weekDayOptions(string $tab, ?int $activeWeekday, string $timezone): array
    {
        if (! $this->supportsWeekdayFilter($tab)) {
            return [];
        }

        [$startsAt, $endsAt] = $this->tabRange($tab, $timezone);
        $shortLabels = $this->weekdayShortLabels();
        $fullLabels = $this->weekdayLabels();
        $options = [];

        for ($date = $startsAt->startOfDay(); $date->lessThanOrEqualTo($endsAt); $date = $date->addDay()) {
            $weekday = $date->isoWeekday();

            $options[] = [
                'weekday' => $weekday,
                'label' => $shortLabels[$weekday],
                'full_label' => $fullLabels[$weekday],
                'date_label' => $date->format('d.m'),
                'active' => $activeWeekday === $weekday,
            ];
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function weekdayLabels(): array
    {
        return [
            1 => __('app.monday'),
            2 => __('app.tuesday'),
            3 => __('app.wednesday'),
            4 => __('app.thursday'),
            5 => __('app.friday'),
            6 => __('app.saturday'),
            7 => __('app.sunday'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function weekdayShortLabels(): array
    {
        return [
            1 => __('app.weekday_short_monday'),
            2 => __('app.weekday_short_tuesday'),
            3 => __('app.weekday_short_wednesday'),
            4 => __('app.weekday_short_thursday'),
            5 => __('app.weekday_short_friday'),
            6 => __('app.weekday_short_saturday'),
            7 => __('app.weekday_short_sunday'),
        ];
    }

    /**
     * @param  array<int, int>  $allowedIds
     * @return array<int, int>
     */
    private function selectedIds(Request $request, string $key, array $allowedIds): array
    {
        return collect($request->array($key))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => in_array($id, $allowedIds, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Trainer>  $filterTrainers
     */
    private function currentTrainerFor(Account $account, Request $request, Collection $filterTrainers): ?Trainer
    {
        $user = $request->user();

        if (! $user || $account->membershipFor($user)?->role !== AccountRole::Trainer) {
            return null;
        }

        return $filterTrainers->first(fn (Trainer $trainer): bool => $trainer->user_id === $user->id);
    }

    private function pastCutoff(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone)
            ->subHour()
            ->timezone(config('app.timezone'));
    }

    /**
     * @param  array<int, int>  $selectedLocationIds
     * @param  array<int, int>  $selectedRoomIds
     * @param  array<int, int>  $selectedTrainerIds
     * @return array<string, mixed>
     */
    private function activeFilterQuery(
        array $selectedLocationIds,
        array $selectedRoomIds,
        array $selectedTrainerIds,
        bool $showOnlyMyClasses,
        bool $showPassed,
    ): array {
        $filters = [];

        if ($selectedLocationIds !== []) {
            $filters['locations'] = $selectedLocationIds;
        }

        if ($selectedRoomIds !== []) {
            $filters['rooms'] = $selectedRoomIds;
        }

        if ($selectedTrainerIds !== []) {
            $filters['trainers'] = $selectedTrainerIds;
        }

        if ($showOnlyMyClasses) {
            $filters['only_my_classes'] = 1;
        }

        if ($showPassed) {
            $filters['show_passed'] = 1;
        }

        return $filters;
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
