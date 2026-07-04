<?php

namespace App\Support;

use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\WebsiteLeadStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class StudioDashboardData
{
    private const OUTLOOK_DAYS = 7;

    public function __construct(
        private readonly UnreservedClassPassBookingIssues $unreservedClassPassBookingIssues,
    ) {}

    /**
     * @return array{
     *     mode: string,
     *     timezone: string,
     *     ownerDashboard?: array<string, mixed>,
     *     trainer: ?Trainer,
     *     trainerDashboard?: array<string, mixed>
     * }
     */
    public function forAccount(Account $account, User $user): array
    {
        $membership = $account->membershipFor($user);
        $timezone = $this->timezone($account);

        if ($membership?->role === AccountRole::Trainer) {
            $trainer = $account->trainers()
                ->with('trainerType')
                ->whereBelongsTo($user, 'user')
                ->first();

            return [
                'mode' => 'trainer',
                'timezone' => $timezone,
                'trainer' => $trainer,
                'trainerDashboard' => $this->trainerDashboard($account, $trainer, $timezone),
            ];
        }

        return [
            'mode' => 'owner',
            'timezone' => $timezone,
            'trainer' => null,
            'ownerDashboard' => $this->ownerDashboard($account, $timezone),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerDashboard(Account $account, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        $todayStartsAt = $now->startOfDay();
        $todayEndsAt = $now->endOfDay();
        $outlookEndsAt = $todayStartsAt->addDays(self::OUTLOOK_DAYS - 1)->endOfDay();

        $todayClasses = $this->visibleClasses($account, $todayStartsAt, $todayEndsAt);
        $outlookClasses = $this->scheduledClasses($account, $todayStartsAt, $outlookEndsAt);
        $todayScheduledClasses = $outlookClasses->filter(
            fn (ScheduledClass $scheduledClass): bool => $this->displayDateKey($scheduledClass, $timezone) === $todayStartsAt->toDateString(),
        )->values();

        return [
            'now' => $now,
            'todayStartsAt' => $todayStartsAt,
            'todayEndsAt' => $todayEndsAt,
            'metrics' => [
                'activePasses' => $account->customerClassPasses()->active()->count(),
                'customers' => $account->customers()->count(),
                'newCustomers' => $account->customers()
                    ->where('created_at', '>=', $todayStartsAt->subDays(6)->timezone(config('app.timezone')))
                    ->count(),
                'openLeads' => $account->websiteLeads()
                    ->whereIn('status', [
                        WebsiteLeadStatus::New->value,
                        WebsiteLeadStatus::Callback->value,
                    ])
                    ->count(),
                'todayNewLeads' => $account->websiteLeads()
                    ->where('status', WebsiteLeadStatus::New->value)
                    ->whereBetween('created_at', $this->databaseRange($todayStartsAt, $todayEndsAt))
                    ->count(),
                'todayLoad' => $this->loadFor($todayScheduledClasses),
            ],
            'problems' => $this->problems($account),
            'liveClasses' => $todayClasses
                ->filter(fn (ScheduledClass $scheduledClass): bool => $scheduledClass->starts_at->lessThanOrEqualTo($now->timezone(config('app.timezone')))
                    && $scheduledClass->ends_at->greaterThanOrEqualTo($now->timezone(config('app.timezone'))))
                ->values(),
            'nextClasses' => $todayClasses
                ->filter(fn (ScheduledClass $scheduledClass): bool => $scheduledClass->starts_at->greaterThan($now->timezone(config('app.timezone'))))
                ->take(6)
                ->values(),
            'locationLoad' => $this->loadBy($outlookClasses, 'location_id', 'location'),
            'roomLoad' => $this->loadBy($outlookClasses, 'room_id', 'room'),
            'outlookDays' => $this->outlookDays($outlookClasses, $todayStartsAt, $timezone),
            'activeTrainerSubstitutions' => $this->activeTrainerSubstitutions($account, $todayStartsAt),
        ];
    }

    /**
     * @return array<int, array{key: string, count: int, label: string, url: string, accent: string}>
     */
    private function problems(Account $account): array
    {
        return [
            [
                'key' => 'unpaid_class_passes',
                'count' => $account->customerClassPasses()->active()->unpaid()->count(),
                'label' => __('app.problem_unpaid_class_passes'),
                'url' => route('dashboard.accounts.customer-class-passes.index', [
                    'account' => $account,
                    'state' => 'active',
                    'payment_status' => 'unpaid',
                ]),
                'accent' => 'danger',
            ],
            [
                'key' => 'partial_class_passes',
                'count' => $account->customerClassPasses()->active()->partiallyPaid()->count(),
                'label' => __('app.problem_partial_class_passes'),
                'url' => route('dashboard.accounts.customer-class-passes.index', [
                    'account' => $account,
                    'state' => 'active',
                    'payment_status' => 'partial',
                ]),
                'accent' => 'warning',
            ],
            [
                'key' => 'unreserved_bookings',
                'count' => $this->unreservedClassPassBookingIssues->countForAccount($account),
                'label' => __('app.problem_unreserved_bookings'),
                'url' => route('dashboard.accounts.trainers.index', $account),
                'accent' => 'warning',
            ],
            [
                'key' => 'freezed_class_passes',
                'count' => $account->customerClassPasses()->freezed()->count(),
                'label' => __('app.problem_freezed_class_passes'),
                'url' => route('dashboard.accounts.customer-class-passes.index', [
                    'account' => $account,
                    'state' => 'freezed',
                ]),
                'accent' => 'scheduled',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trainerDashboard(Account $account, ?Trainer $trainer, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        $todayStartsAt = $now->startOfDay();
        $tomorrowStartsAt = $todayStartsAt->addDay();
        $weekEndsAt = $todayStartsAt->endOfWeek(CarbonInterface::SUNDAY);

        if (! $trainer) {
            return [
                'now' => $now,
                'todayClasses' => collect(),
                'tomorrowClasses' => collect(),
                'weekDays' => collect(),
                'bookingStatuses' => ClassBookingStatus::cases(),
            ];
        }

        $classes = $account->scheduledClasses()
            ->with($this->classRelations(includeAllBookings: true))
            ->whereBelongsTo($trainer)
            ->where('status', '!=', ScheduledClassStatus::Draft->value)
            ->whereBetween('starts_at', $this->databaseRange($todayStartsAt, $weekEndsAt))
            ->orderBy('starts_at')
            ->get();

        return [
            'now' => $now,
            'todayClasses' => $this->classesForDate($classes, $todayStartsAt, $timezone),
            'tomorrowClasses' => $this->classesForDate($classes, $tomorrowStartsAt, $timezone),
            'weekDays' => $this->remainingWeekDays($classes, $todayStartsAt, $weekEndsAt, $timezone),
            'bookingStatuses' => ClassBookingStatus::cases(),
        ];
    }

    /**
     * @return Collection<int, ScheduledClass>
     */
    private function visibleClasses(Account $account, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Collection
    {
        return $account->scheduledClasses()
            ->with($this->classRelations())
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->whereBetween('starts_at', $this->databaseRange($startsAt, $endsAt))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return Collection<int, ScheduledClass>
     */
    private function scheduledClasses(Account $account, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Collection
    {
        return $account->scheduledClasses()
            ->with($this->classRelations())
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->whereBetween('starts_at', $this->databaseRange($startsAt, $endsAt))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function classRelations(bool $includeAllBookings = false): array
    {
        $bookingStatuses = $includeAllBookings
            ? array_map(fn (ClassBookingStatus $status): string => $status->value, ClassBookingStatus::cases())
            : $this->activeBookingStatuses();

        return [
            'location',
            'room.location',
            'classType.activityDirection',
            'trainer.trainerType',
            'classBookings' => fn ($query) => $query
                ->notCorrectedRemoved()
                ->whereIn('status', $bookingStatuses)
                ->with('customer:id,name,phone,email')
                ->orderBy('created_at'),
        ];
    }

    /**
     * @param  Collection<int, ScheduledClass>  $classes
     * @return array{classes: int, bookings: int, capacity: int, percent: int}
     */
    private function loadFor(Collection $classes): array
    {
        $capacity = $classes->sum(fn (ScheduledClass $scheduledClass): int => max(0, (int) ($scheduledClass->capacity ?? 0)));
        $bookings = $classes->sum(fn (ScheduledClass $scheduledClass): int => $this->activeBookingsCount($scheduledClass));

        return [
            'classes' => $classes->count(),
            'bookings' => $bookings,
            'capacity' => $capacity,
            'percent' => $capacity > 0 ? (int) round(($bookings / $capacity) * 100) : 0,
        ];
    }

    /**
     * @param  Collection<int, ScheduledClass>  $classes
     * @return Collection<int, array{name: ?string, secondary: ?string, classes: int, bookings: int, capacity: int, percent: int}>
     */
    private function loadBy(Collection $classes, string $foreignKey, string $relation): Collection
    {
        return $classes
            ->groupBy(fn (ScheduledClass $scheduledClass): string => (string) ($scheduledClass->{$foreignKey} ?? 0))
            ->map(function (Collection $group) use ($relation): array {
                /** @var ScheduledClass $first */
                $first = $group->first();
                $load = $this->loadFor($group);
                $related = $first->{$relation};

                return [
                    'name' => $related?->name,
                    'secondary' => $relation === 'room' ? $first->location?->name : null,
                    ...$load,
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['bookings'])
            ->values();
    }

    /**
     * @param  Collection<int, ScheduledClass>  $classes
     * @return Collection<int, array{date: CarbonImmutable, classes: int, bookings: int, capacity: int, percent: int}>
     */
    private function outlookDays(Collection $classes, CarbonImmutable $startsAt, string $timezone): Collection
    {
        return collect(range(0, self::OUTLOOK_DAYS - 1))
            ->map(function (int $offset) use ($classes, $startsAt, $timezone): array {
                $date = $startsAt->addDays($offset);
                $dayClasses = $this->classesForDate($classes, $date, $timezone);

                return [
                    'date' => $date,
                    ...$this->loadFor($dayClasses),
                ];
            });
    }

    /**
     * @param  Collection<int, ScheduledClass>  $classes
     * @return Collection<int, array{date: CarbonImmutable, classes: Collection<int, ScheduledClass>}>
     */
    private function remainingWeekDays(Collection $classes, CarbonImmutable $todayStartsAt, CarbonImmutable $weekEndsAt, string $timezone): Collection
    {
        $firstDate = $todayStartsAt->addDays(2);

        if ($firstDate->greaterThan($weekEndsAt)) {
            return collect();
        }

        return collect(range(0, (int) $firstDate->diffInDays($weekEndsAt)))
            ->map(function (int $offset) use ($classes, $firstDate, $timezone): array {
                $date = $firstDate->addDays($offset);

                return [
                    'date' => $date,
                    'classes' => $this->classesForDate($classes, $date, $timezone),
                ];
            });
    }

    /**
     * @param  Collection<int, ScheduledClass>  $classes
     * @return Collection<int, ScheduledClass>
     */
    private function classesForDate(Collection $classes, CarbonImmutable $date, string $timezone): Collection
    {
        $dateKey = $date->toDateString();

        return $classes
            ->filter(fn (ScheduledClass $scheduledClass): bool => $this->displayDateKey($scheduledClass, $timezone) === $dateKey)
            ->values();
    }

    private function activeBookingsCount(ScheduledClass $scheduledClass): int
    {
        return $scheduledClass->classBookings
            ->filter(fn (ClassBooking $booking): bool => in_array($booking->status->value, $this->activeBookingStatuses(), true))
            ->count();
    }

    /**
     * @return Collection<int, mixed>
     */
    private function activeTrainerSubstitutions(Account $account, CarbonImmutable $todayStartsAt): Collection
    {
        return $account->trainerSubstitutions()
            ->with(['replacedTrainer:id,name', 'substituteTrainer:id,name', 'location:id,name', 'room:id,name'])
            ->whereDate('date_to', '>=', $todayStartsAt->toDateString())
            ->orderBy('date_from')
            ->orderByDesc('created_at')
            ->take(8)
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function activeBookingStatuses(): array
    {
        return [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function databaseRange(CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        return [
            $startsAt->timezone(config('app.timezone')),
            $endsAt->timezone(config('app.timezone')),
        ];
    }

    private function displayDateKey(ScheduledClass $scheduledClass, string $timezone): string
    {
        return $scheduledClass->starts_at->copy()->timezone($timezone)->toDateString();
    }

    private function timezone(Account $account): string
    {
        return $account->timezone ?: config('app.timezone');
    }
}
