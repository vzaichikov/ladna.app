<?php

namespace App\Http\Controllers;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\ScheduledClass;
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

    public function show(Request $request, string $accountSlug, string $locationSlug): View
    {
        return view('public.schedule', $this->scheduleViewData($request, $accountSlug, $locationSlug, false));
    }

    public function embed(Request $request, string $accountSlug, string $locationSlug): View
    {
        return view('public.schedule', $this->scheduleViewData($request, $accountSlug, $locationSlug, true));
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

        return [
            'account' => $account,
            'location' => $location,
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
                'classBookings as active_bookings_count' => fn ($query) => $query->whereIn('status', $activeStatuses),
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
