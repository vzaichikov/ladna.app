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

        $scheduledClasses = $account->scheduledClasses()
            ->with(['location', 'room', 'classType.activityDirection', 'trainer', 'scheduleSeries', 'classBookings.customer'])
            ->whereBetween('starts_at', [
                $startsAt->timezone(config('app.timezone')),
                $endsAt->timezone(config('app.timezone')),
            ])
            ->orderBy('starts_at')
            ->get();

        return view('scheduled-classes.index', [
            'account' => $account,
            'activeTab' => $activeTab,
            'tabs' => $this->tabs(),
            'scheduledClassDays' => $scheduledClasses->groupBy(fn ($scheduledClass): string => $scheduledClass->starts_at->copy()
                ->timezone($scheduledClass->displayTimezone())
                ->toDateString()),
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
}
