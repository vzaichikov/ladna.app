<?php

namespace App\Http\Controllers;

use App\Enums\ClassBookingStatus;
use App\Enums\PublicScheduleView;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Support\ManualQuickBookingAvailability;
use App\Support\ScheduleKindRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PublicScheduleController extends Controller
{
    private const DEFAULT_PERIOD = 'week';

    /**
     * @var array<int, string>
     */
    private const PERIODS = ['today', 'tomorrow', 'week', 'month'];

    public function show(Request $request, string $accountSlug, string $locationSlug): View|string
    {
        $data = $this->scheduleViewData($request, $accountSlug, $locationSlug, false);

        return view($data['scheduleView']->viewName(), $data)
            ->fragmentIf($request->ajax(), 'schedule-results');
    }

    public function embed(Request $request, string $accountSlug, string $locationSlug): View|string
    {
        $data = $this->scheduleViewData($request, $accountSlug, $locationSlug, true);

        return view($data['scheduleView']->viewName(), $data)
            ->fragmentIf($request->ajax(), 'schedule-results');
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleViewData(Request $request, string $accountSlug, string $locationSlug, bool $isEmbed): array
    {
        [$account, $location, $classes, $selectedPeriod, $periodRange] = $this->scheduleFor($request, $accountSlug, $locationSlug);
        $selectedRoomSlug = $request->query('room');
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $classDays = $this->groupByDisplayDate($classes);
        $customer = $this->currentCustomerFor($account);
        $scheduleView = $account->publicScheduleView();

        $data = [
            'account' => $account,
            'location' => $location,
            'scheduleView' => $scheduleView,
            'classes' => $classes,
            'classDays' => $classDays,
            'dateAnchors' => $this->dateAnchors($classDays, $timezone),
            'rooms' => $location->rooms()->active()->orderBy('name')->get(),
            'selectedRoomSlug' => $selectedRoomSlug,
            'selectedPeriod' => $selectedPeriod,
            'periodOptions' => $this->periodOptions($account, $location, $selectedRoomSlug, $isEmbed, $selectedPeriod, $timezone),
            'periodRange' => $periodRange,
            'manualCtaOptions' => $this->manualCtaOptions($account),
            'customer' => $customer,
            'customerPasses' => $customer ? $this->activeCustomerPasses($customer) : collect(),
            'isEmbed' => $isEmbed,
        ];

        if ($scheduleView === PublicScheduleView::CompactBooking) {
            $data['compactSchedule'] = $this->compactScheduleData($request, $account, $location, $isEmbed);
        }

        return $data;
    }

    /**
     * @return array{0: Account, 1: Location, 2: Collection<int, ScheduledClass>, 3: string, 4: array{start: Carbon, end: Carbon}}
     */
    private function scheduleFor(Request $request, string $accountSlug, string $locationSlug): array
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $this->setAccountLocale($account);

        $location = $account->locations()
            ->where('slug', $locationSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $selectedPeriod = $this->selectedPeriod($request);
        $periodRange = $this->periodRange($selectedPeriod, $timezone);
        $activeStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];

        $classes = $location->scheduledClasses()
            ->publicUpcoming()
            ->when($request->query('room'), fn ($query, $roomSlug) => $query->whereHas('room', fn ($query) => $query->where('slug', $roomSlug)))
            ->with(['account', 'location', 'room', 'classType.activityDirection', 'trainer'])
            ->withCount([
                'classBookings as active_bookings_count' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->whereIn('status', $activeStatuses),
            ])
            ->whereBetween('starts_at', [
                $periodRange['start']->copy()->timezone(config('app.timezone')),
                $periodRange['end']->copy()->timezone(config('app.timezone')),
            ])
            ->limit($selectedPeriod === 'month' ? 200 : 100)
            ->get();

        return [$account, $location, $classes, $selectedPeriod, $periodRange];
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }

    private function selectedPeriod(Request $request): string
    {
        $period = (string) $request->query('period', self::DEFAULT_PERIOD);

        return in_array($period, self::PERIODS, true) ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    private function periodRange(string $period, string $timezone): array
    {
        $today = now($timezone)->startOfDay();

        return match ($period) {
            'today' => [
                'start' => $today->copy(),
                'end' => $today->copy()->endOfDay(),
            ],
            'tomorrow' => [
                'start' => $today->copy()->addDay(),
                'end' => $today->copy()->addDay()->endOfDay(),
            ],
            'month' => [
                'start' => $today->copy(),
                'end' => $today->copy()->addDays(30)->endOfDay(),
            ],
            default => [
                'start' => $today->copy(),
                'end' => $today->copy()->addDays(7)->endOfDay(),
            ],
        };
    }

    private function translatedDate(Carbon $date): string
    {
        return $date->translatedFormat('l, j F');
    }

    /**
     * @return array<int, array{key: string, label: string, date: string, url: string, active: bool}>
     */
    private function periodOptions(Account $account, Location $location, mixed $selectedRoomSlug, bool $isEmbed, string $selectedPeriod, string $timezone): array
    {
        return collect(self::PERIODS)
            ->map(function (string $period) use ($account, $location, $selectedRoomSlug, $isEmbed, $selectedPeriod, $timezone): array {
                $range = $this->periodRange($period, $timezone);
                $query = ['period' => $period];

                if (is_string($selectedRoomSlug) && $selectedRoomSlug !== '') {
                    $query['room'] = $selectedRoomSlug;
                }

                return [
                    'key' => $period,
                    'label' => __('app.schedule_period_'.$period),
                    'date' => $period === 'today' || $period === 'tomorrow'
                        ? $this->translatedDate($range['start'])
                        : $this->translatedDate($range['start']).' - '.$this->translatedDate($range['end']),
                    'url' => $this->scheduleUrl($account, $location, $isEmbed, $query),
                    'active' => $period === $selectedPeriod,
                ];
            })
            ->values()
            ->all();
    }

    private function scheduleUrl(Account $account, Location $location, bool $isEmbed, array $query = []): string
    {
        return route($isEmbed ? 'public.schedule.embed' : 'public.schedule', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            ...$query,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function compactScheduleData(Request $request, Account $account, Location $location, bool $isEmbed): array
    {
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $scheduleKinds = $this->enabledScheduleKinds($account);
        $manualKinds = collect(ScheduleKindRegistry::manualKinds())
            ->filter(fn (ScheduleKind $scheduleKind): bool => in_array($scheduleKind, $scheduleKinds, true))
            ->values()
            ->all();
        $selectedManualKind = $this->selectedCompactManualKind($request, $manualKinds);
        $groupClassTypes = $account->classTypes()
            ->active()
            ->where('schedule_kind', ScheduleKind::GroupClass->value)
            ->whereIn('id', $location->scheduledClasses()
                ->publicUpcoming()
                ->select('class_type_id'))
            ->orderBy('name')
            ->get();
        $manualClassTypes = $selectedManualKind
            ? $account->classTypes()
                ->active()
                ->where('schedule_kind', $selectedManualKind->value)
                ->orderBy('name')
                ->get()
            : collect();
        $rooms = $location->rooms()->active()->orderBy('name')->get();
        $trainers = $account->trainers()->active()->with('trainerType')->orderBy('name')->get();
        $selectedGroupClassTypeId = $this->selectedModelId($request->query('group_class_type', $selectedManualKind ? null : $request->query('class_type')), $groupClassTypes);
        $selectedGroupRoomId = $this->selectedModelId($request->query('group_room', $selectedManualKind ? null : $request->query('room')), $rooms);
        $selectedGroupTrainerId = $this->selectedModelId($request->query('group_trainer', $selectedManualKind ? null : $request->query('trainer')), $trainers);
        $scheduledMonths = $this->compactScheduledMonths($location, $selectedGroupClassTypeId, $selectedGroupTrainerId, $selectedGroupRoomId, $timezone);
        $selectedDate = $this->selectedCompactDate($request, $timezone, $scheduledMonths);
        $selectedMonth = $selectedDate->copy()->startOfMonth();
        $selectedManualClassTypeId = $this->selectedModelId($request->query('class_type'), $manualClassTypes);
        $selectedManualRoomId = $this->selectedModelId($request->query('room'), $rooms);
        $selectedManualTrainerId = $this->selectedModelId(
            $request->query('trainer'),
            $trainers,
            false,
        );
        $groupQuery = $this->compactGroupQuery($selectedManualKind, $selectedDate, $selectedGroupClassTypeId, $selectedGroupTrainerId, $selectedGroupRoomId);
        $manualQuery = $this->compactManualQuery($selectedManualKind, $selectedDate, $selectedManualClassTypeId, $selectedManualTrainerId, $selectedManualRoomId);
        $groupPanel = $this->selectedCompactPanel($request->query('group_panel'), ['class_type', 'trainer', 'room']);
        $manualPanel = $selectedManualKind
            ? $this->selectedCompactPanel($request->query('manual_panel'), ['service', 'date', 'trainer', 'room'])
            : null;

        if ($selectedManualKind !== ScheduleKind::PrivateLesson && $manualPanel === 'trainer') {
            $manualPanel = null;
        }

        if ($selectedManualKind) {
            $groupPanel = null;
        }

        $groupClasses = $this->compactGroupClasses(
            $account,
            $location,
            $selectedDate,
            $selectedGroupClassTypeId,
            $selectedGroupTrainerId,
            $selectedGroupRoomId,
        );
        $manualAvailability = null;
        $manualRequiredFilters = [];

        if ($selectedManualKind) {
            $manualRequiredFilters = $this->manualRequiredFilters($selectedManualKind, $selectedManualClassTypeId, $selectedManualTrainerId, $selectedManualRoomId);

            if ($manualRequiredFilters === []) {
                $manualAvailability = app(ManualQuickBookingAvailability::class)->for($account, $selectedManualKind, [
                    'date' => $selectedDate->toDateString(),
                    'location_id' => $location->id,
                    'room_id' => (int) $selectedManualRoomId,
                    'class_type_id' => (int) $selectedManualClassTypeId,
                    'trainer_id' => $selectedManualTrainerId,
                ]);
            }
        }

        return [
            'selectedKind' => $selectedManualKind ?? ScheduleKind::GroupClass,
            'selectedManualKind' => $selectedManualKind,
            'selectedDate' => $selectedDate,
            'selectedMonth' => $selectedMonth,
            'selectedClassTypeId' => $selectedGroupClassTypeId,
            'selectedTrainerId' => $selectedGroupTrainerId,
            'selectedRoomId' => $selectedGroupRoomId,
            'selectedManualClassTypeId' => $selectedManualClassTypeId,
            'selectedManualTrainerId' => $selectedManualTrainerId,
            'selectedManualRoomId' => $selectedManualRoomId,
            'selectedQuery' => $groupQuery,
            'manualQuery' => $manualQuery,
            'groupPanel' => $groupPanel,
            'manualPanel' => $manualPanel,
            'monthOptions' => $this->compactMonthOptions($account, $location, $isEmbed, $groupQuery, $selectedMonth, $scheduledMonths),
            'dateOptions' => $this->compactDateOptions($account, $location, $isEmbed, $groupQuery, $selectedDate, $selectedMonth, $timezone),
            'manualMonthOptions' => $selectedManualKind ? $this->compactManualMonthOptions($account, $location, $isEmbed, $manualQuery, $selectedMonth, $timezone) : [],
            'manualDateOptions' => $selectedManualKind ? $this->compactDateOptions($account, $location, $isEmbed, $manualQuery, $selectedDate, $selectedMonth, $timezone) : [],
            'manualActionOptions' => $this->compactManualActionOptions($account, $location, $isEmbed, $manualKinds, $selectedManualKind, $selectedDate, $groupQuery),
            'classTypeOptions' => $this->compactFilterOptions($account, $location, $isEmbed, $groupQuery, 'group_class_type', $groupClassTypes, $selectedGroupClassTypeId),
            'trainerOptions' => $this->compactFilterOptions($account, $location, $isEmbed, $groupQuery, 'group_trainer', $trainers, $selectedGroupTrainerId),
            'roomOptions' => $this->compactFilterOptions($account, $location, $isEmbed, $groupQuery, 'group_room', $rooms, $selectedGroupRoomId),
            'manualClassTypeOptions' => $selectedManualKind ? $this->compactFilterOptions($account, $location, $isEmbed, $manualQuery, 'class_type', $manualClassTypes, $selectedManualClassTypeId) : [],
            'manualTrainerOptions' => $selectedManualKind === ScheduleKind::PrivateLesson ? $this->compactFilterOptions($account, $location, $isEmbed, $manualQuery, 'trainer', $trainers, $selectedManualTrainerId) : [],
            'manualRoomOptions' => $selectedManualKind ? $this->compactFilterOptions($account, $location, $isEmbed, $manualQuery, 'room', $rooms, $selectedManualRoomId) : [],
            'classTypes' => $groupClassTypes,
            'manualClassTypes' => $manualClassTypes,
            'trainers' => $trainers,
            'rooms' => $rooms,
            'classes' => $groupClasses,
            'manualAvailability' => $manualAvailability,
            'manualRequiredFilters' => $manualRequiredFilters,
        ];
    }

    /**
     * @param  array<int, ScheduleKind>  $scheduleKinds
     */
    private function selectedCompactManualKind(Request $request, array $scheduleKinds): ?ScheduleKind
    {
        $requestedKind = ScheduleKind::tryFrom((string) $request->query('kind'));

        if ($requestedKind && in_array($requestedKind, $scheduleKinds, true)) {
            return $requestedKind;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $allowedPanels
     */
    private function selectedCompactPanel(mixed $value, array $allowedPanels): ?string
    {
        $panel = (string) $value;

        return in_array($panel, $allowedPanels, true) ? $panel : null;
    }

    /**
     * @return array<int, ScheduleKind>
     */
    private function enabledScheduleKinds(Account $account): array
    {
        return collect(ScheduleKindRegistry::all())
            ->map(fn (array $definition): ScheduleKind => $definition['kind'])
            ->filter(fn (ScheduleKind $scheduleKind): bool => $account->hasScheduleKindEnabled($scheduleKind))
            ->values()
            ->all();
    }

    /**
     * @param  SupportCollection<int, array{month: Carbon, first_date: Carbon}>  $scheduledMonths
     */
    private function selectedCompactDate(Request $request, string $timezone, SupportCollection $scheduledMonths): Carbon
    {
        $today = now($timezone)->startOfDay();
        $date = (string) $request->query('date', '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            try {
                $selectedDate = Carbon::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', $timezone)->startOfDay();

                return $selectedDate->lessThan($today) ? $today : $selectedDate;
            } catch (\Throwable) {
            }
        }

        $month = (string) $request->query('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            try {
                $selectedMonth = Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', $timezone)->startOfMonth();

                if ($selectedMonth->lessThan($today->copy()->startOfMonth())) {
                    return $today;
                }

                $matchingMonth = $scheduledMonths->first(fn (array $option): bool => $option['month']->isSameMonth($selectedMonth));

                if ($matchingMonth) {
                    return $matchingMonth['first_date']->lessThan($today) ? $today : $matchingMonth['first_date'];
                }

                return $selectedMonth->isSameMonth($today) ? $today : $selectedMonth;
            } catch (\Throwable) {
            }
        }

        $firstScheduledMonth = $scheduledMonths->first();

        if ($firstScheduledMonth) {
            return $firstScheduledMonth['first_date']->lessThan($today) ? $today : $firstScheduledMonth['first_date'];
        }

        return $today;
    }

    /**
     * @param  SupportCollection<int, ClassType|Room|Trainer>  $models
     */
    private function selectedModelId(mixed $value, SupportCollection $models, bool $defaultToFirst = false): ?int
    {
        $id = (int) $value;

        if ($id > 0 && $models->contains('id', $id)) {
            return $id;
        }

        return $defaultToFirst ? $models->first()?->id : null;
    }

    /**
     * @return Collection<int, ScheduledClass>
     */
    private function compactGroupClasses(
        Account $account,
        Location $location,
        Carbon $selectedDate,
        ?int $selectedClassTypeId,
        ?int $selectedTrainerId,
        ?int $selectedRoomId,
    ): Collection {
        $activeStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];
        $dayStart = $selectedDate->copy()->startOfDay();
        $dayEnd = $selectedDate->copy()->endOfDay();

        return $location->scheduledClasses()
            ->publicUpcoming()
            ->with(['account', 'location', 'room', 'classType.activityDirection', 'trainer.trainerType'])
            ->withCount([
                'classBookings as active_bookings_count' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->whereIn('status', $activeStatuses),
            ])
            ->whereBetween('starts_at', [
                $dayStart->copy()->timezone(config('app.timezone')),
                $dayEnd->copy()->timezone(config('app.timezone')),
            ])
            ->when($selectedClassTypeId, fn ($query) => $query->where('class_type_id', $selectedClassTypeId))
            ->when($selectedTrainerId, fn ($query) => $query->where('trainer_id', $selectedTrainerId))
            ->when($selectedRoomId, fn ($query) => $query->where('room_id', $selectedRoomId))
            ->orderBy('starts_at')
            ->limit(100)
            ->get();
    }

    /**
     * @return array<string, string>
     */
    private function compactGroupQuery(
        ?ScheduleKind $selectedManualKind,
        Carbon $selectedDate,
        ?int $selectedClassTypeId,
        ?int $selectedTrainerId,
        ?int $selectedRoomId,
    ): array {
        return array_filter([
            'kind' => $selectedManualKind?->value,
            'date' => $selectedDate->toDateString(),
            'group_class_type' => $selectedClassTypeId,
            'group_trainer' => $selectedTrainerId,
            'group_room' => $selectedRoomId,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, string>
     */
    private function compactManualQuery(
        ?ScheduleKind $selectedManualKind,
        Carbon $selectedDate,
        ?int $selectedClassTypeId,
        ?int $selectedTrainerId,
        ?int $selectedRoomId,
    ): array {
        return array_filter([
            'kind' => $selectedManualKind?->value,
            'date' => $selectedDate->toDateString(),
            'class_type' => $selectedClassTypeId,
            'trainer' => $selectedManualKind === ScheduleKind::PrivateLesson ? $selectedTrainerId : null,
            'room' => $selectedRoomId,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array{date: string, label: string, day: string, weekday: string, url: string, active: bool}>
     */
    private function compactDateOptions(Account $account, Location $location, bool $isEmbed, array $query, Carbon $selectedDate, Carbon $selectedMonth, string $timezone): array
    {
        $today = now($timezone)->startOfDay();
        $firstDate = $selectedMonth->copy()->startOfMonth();
        $lastDate = $selectedMonth->copy()->endOfMonth();

        if ($firstDate->isSameMonth($today) && $firstDate->lessThan($today)) {
            $firstDate = $today;
        }

        if ($firstDate->greaterThan($lastDate)) {
            $firstDate = $today;
            $lastDate = $today;
        }

        return collect(range(0, (int) $firstDate->diffInDays($lastDate)))
            ->map(function (int $offset) use ($account, $location, $isEmbed, $query, $selectedDate, $firstDate): array {
                $date = $firstDate->copy()->addDays($offset);
                $dateQuery = [
                    ...$query,
                    'date' => $date->toDateString(),
                    'month' => $date->format('Y-m'),
                ];

                return [
                    'date' => $date->toDateString(),
                    'label' => $date->isToday() ? __('app.today') : ($date->isTomorrow() ? __('app.tomorrow') : $date->translatedFormat('D')),
                    'day' => $date->format('j'),
                    'weekday' => $date->translatedFormat('D'),
                    'url' => $this->scheduleUrl($account, $location, $isEmbed, $dateQuery),
                    'active' => $date->isSameDay($selectedDate),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  SupportCollection<int, array{month: Carbon, first_date: Carbon}>  $scheduledMonths
     * @return array<int, array{month: string, label: string, year: string, url: string, active: bool}>
     */
    private function compactMonthOptions(Account $account, Location $location, bool $isEmbed, array $query, Carbon $selectedMonth, SupportCollection $scheduledMonths): array
    {
        $monthQuery = $query;
        unset($monthQuery['date']);

        return $scheduledMonths
            ->map(function (array $option) use ($account, $location, $isEmbed, $monthQuery, $selectedMonth): array {
                $month = $option['month'];
                $date = $option['first_date'];

                return [
                    'month' => $month->format('Y-m'),
                    'label' => $month->translatedFormat('F'),
                    'year' => $month->format('Y'),
                    'url' => $this->scheduleUrl($account, $location, $isEmbed, [
                        ...$monthQuery,
                        'date' => $date->toDateString(),
                        'month' => $month->format('Y-m'),
                    ]),
                    'active' => $month->isSameMonth($selectedMonth),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array{month: string, label: string, year: string, url: string, active: bool}>
     */
    private function compactManualMonthOptions(Account $account, Location $location, bool $isEmbed, array $query, Carbon $selectedMonth, string $timezone): array
    {
        $today = now($timezone)->startOfDay();
        $startMonth = $today->copy()->startOfMonth();
        $manualMonthQuery = $query;
        unset($manualMonthQuery['date']);

        return collect(range(0, 5))
            ->map(fn (int $offset): Carbon => $startMonth->copy()->addMonths($offset))
            ->when(
                ! collect(range(0, 5))->contains(fn (int $offset): bool => $startMonth->copy()->addMonths($offset)->isSameMonth($selectedMonth)),
                fn (SupportCollection $months): SupportCollection => $months->push($selectedMonth->copy()->startOfMonth()),
            )
            ->sortBy(fn (Carbon $month): int => $month->getTimestamp())
            ->values()
            ->map(function (Carbon $month) use ($account, $location, $isEmbed, $manualMonthQuery, $selectedMonth, $today): array {
                $date = $month->isSameMonth($today) ? $today : $month->copy()->startOfMonth();

                return [
                    'month' => $month->format('Y-m'),
                    'label' => $month->translatedFormat('F'),
                    'year' => $month->format('Y'),
                    'url' => $this->scheduleUrl($account, $location, $isEmbed, [
                        ...$manualMonthQuery,
                        'date' => $date->toDateString(),
                        'month' => $month->format('Y-m'),
                        'manual_panel' => 'date',
                    ]),
                    'active' => $month->isSameMonth($selectedMonth),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, ScheduleKind>  $scheduleKinds
     * @return array<int, array{value: string, label: string, url: string, active: bool, icon: string}>
     */
    private function compactManualActionOptions(Account $account, Location $location, bool $isEmbed, array $scheduleKinds, ?ScheduleKind $selectedKind, Carbon $selectedDate, array $query): array
    {
        return collect($scheduleKinds)
            ->map(fn (ScheduleKind $scheduleKind): array => [
                'value' => $scheduleKind->value,
                'label' => __('app.public_booking_'.$scheduleKind->value.'_cta'),
                'url' => $this->scheduleUrl($account, $location, $isEmbed, [
                    ...$query,
                    'kind' => $scheduleKind->value,
                    'date' => $selectedDate->toDateString(),
                ]),
                'active' => $scheduleKind === $selectedKind,
                'icon' => ScheduleKindRegistry::get($scheduleKind)['icon'],
            ])
            ->all();
    }

    /**
     * @return SupportCollection<int, array{month: Carbon, first_date: Carbon}>
     */
    private function compactScheduledMonths(Location $location, ?int $selectedClassTypeId, ?int $selectedTrainerId, ?int $selectedRoomId, string $timezone): SupportCollection
    {
        return $location->scheduledClasses()
            ->publicUpcoming()
            ->when($selectedClassTypeId, fn ($query) => $query->where('class_type_id', $selectedClassTypeId))
            ->when($selectedTrainerId, fn ($query) => $query->where('trainer_id', $selectedTrainerId))
            ->when($selectedRoomId, fn ($query) => $query->where('room_id', $selectedRoomId))
            ->limit(500)
            ->get(['id', 'starts_at'])
            ->map(function (ScheduledClass $scheduledClass) use ($timezone): array {
                $date = $scheduledClass->starts_at->copy()->timezone($timezone)->startOfDay();

                return [
                    'month_key' => $date->format('Y-m'),
                    'month' => $date->copy()->startOfMonth(),
                    'first_date' => $date,
                    'first_timestamp' => $date->getTimestamp(),
                ];
            })
            ->groupBy('month_key')
            ->map(fn (SupportCollection $items): array => $items->sortBy('first_timestamp')->first())
            ->sortBy('first_timestamp')
            ->values()
            ->map(fn (array $option): array => [
                'month' => $option['month'],
                'first_date' => $option['first_date'],
            ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  Collection<int, ClassType|Room|Trainer>  $models
     * @return array<int, array{id: int, name: string, url: string, active: bool}>
     */
    private function compactFilterOptions(Account $account, Location $location, bool $isEmbed, array $query, string $key, Collection $models, ?int $selectedId): array
    {
        return $models
            ->map(fn (ClassType|Room|Trainer $model): array => [
                'id' => $model->id,
                'name' => $model->name,
                'url' => $this->scheduleUrl($account, $location, $isEmbed, [...$query, $key => $model->id]),
                'active' => $selectedId === $model->id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function manualRequiredFilters(ScheduleKind $scheduleKind, ?int $selectedClassTypeId, ?int $selectedTrainerId, ?int $selectedRoomId): array
    {
        $missing = [];

        if (! $selectedClassTypeId) {
            $missing[] = __('app.class_type');
        }

        if ($scheduleKind === ScheduleKind::PrivateLesson && ! $selectedTrainerId) {
            $missing[] = __('app.trainer');
        }

        if (! $selectedRoomId) {
            $missing[] = __('app.room');
        }

        return $missing;
    }

    /**
     * @param  Collection<int, ScheduledClass>  $classes
     * @return SupportCollection<string, Collection<int, ScheduledClass>>
     */
    private function groupByDisplayDate(Collection $classes): SupportCollection
    {
        return $classes->groupBy(fn (ScheduledClass $scheduledClass): string => $scheduledClass->starts_at
            ->copy()
            ->timezone($scheduledClass->displayTimezone())
            ->toDateString());
    }

    /**
     * @param  SupportCollection<string, Collection<int, ScheduledClass>>  $classDays
     * @return array<int, array{id: string, label: string, count: int}>
     */
    private function dateAnchors(SupportCollection $classDays, string $timezone): array
    {
        return $classDays
            ->map(fn (Collection $classes, string $date): array => [
                'id' => 'schedule-day-'.$date,
                'label' => $this->translatedDate(Carbon::parse($date, $timezone)),
                'count' => $classes->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{kind: ScheduleKind, label: string}>
     */
    private function manualCtaOptions(Account $account): array
    {
        return collect([ScheduleKind::PrivateLesson, ScheduleKind::RoomRental])
            ->filter(fn (ScheduleKind $scheduleKind): bool => $account->hasScheduleKindEnabled($scheduleKind))
            ->map(fn (ScheduleKind $scheduleKind): array => [
                'kind' => $scheduleKind,
                'label' => __('app.public_schedule_'.$scheduleKind->value.'_cta'),
            ])
            ->values()
            ->all();
    }

    private function currentCustomerFor(Account $account): ?Customer
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->account_id === $account->id ? $customer : null;
    }

    /**
     * @return Collection<int, CustomerClassPass>
     */
    private function activeCustomerPasses(Customer $customer): Collection
    {
        return $customer->customerClassPasses()
            ->active()
            ->with('classPassPlan')
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->get();
    }
}
