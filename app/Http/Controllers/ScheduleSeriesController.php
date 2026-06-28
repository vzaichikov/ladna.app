<?php

namespace App\Http\Controllers;

use App\Actions\GenerateAccountSchedule;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleSeriesStatus;
use App\Http\Requests\StoreScheduleSeriesRequest;
use App\Http\Requests\UpdateScheduleSeriesRequest;
use App\Models\Account;
use App\Models\ScheduleSeries;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ScheduleSeriesController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('view', $account);
        $this->ensureGroupClassesEnabled($account);

        return view('schedule-series.index', [
            'account' => $account,
            'series' => $account->scheduleSeries()
                ->with(['location', 'room', 'classType.activityDirection', 'trainer'])
                ->orderBy('weekday')
                ->orderBy('start_time')
                ->get(),
            'weekdays' => $this->weekdays(),
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('manageSchedule', $account);
        $this->ensureGroupClassesEnabled($account);

        return view('schedule-series.create', $this->formData($account, new ScheduleSeries([
            'weekday' => now()->isoWeekday(),
            'start_time' => '18:00',
            'start_date' => now()->toDateString(),
            'status' => ScheduleSeriesStatus::Active,
        ])));
    }

    public function store(StoreScheduleSeriesRequest $request, Account $account, GenerateAccountSchedule $generateAccountSchedule): RedirectResponse
    {
        $validated = $request->validated();
        $this->ensureGroupClassesEnabled($account);
        $this->ensureRoomBelongsToLocation($account, (int) $validated['location_id'], (int) $validated['room_id']);

        $series = $account->scheduleSeries()->create($validated);
        $generateAccountSchedule->execute($account, $series->id);

        return redirect()->route('dashboard.accounts.schedule-series.index', $account)
            ->with('status', __('app.schedule_series_created'));
    }

    public function show(Account $account, ScheduleSeries $scheduleSeries): never
    {
        abort(404);
    }

    public function edit(Account $account, ScheduleSeries $scheduleSeries): View
    {
        $this->ensureBelongsToAccount($account, $scheduleSeries);
        $this->authorize('manageSchedule', $account);
        $this->ensureGroupClassesEnabled($account);

        return view('schedule-series.edit', $this->formData($account, $scheduleSeries));
    }

    public function update(UpdateScheduleSeriesRequest $request, Account $account, ScheduleSeries $scheduleSeries, GenerateAccountSchedule $generateAccountSchedule): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $scheduleSeries);
        $this->ensureGroupClassesEnabled($account);

        $validated = $request->validated();
        $this->ensureRoomBelongsToLocation($account, (int) $validated['location_id'], (int) $validated['room_id']);

        $scheduleSeries->update($validated);
        $generateAccountSchedule->execute($account, $scheduleSeries->id);

        return redirect()->route('dashboard.accounts.schedule-series.index', $account)
            ->with('status', __('app.schedule_series_updated'));
    }

    public function destroy(Account $account, ScheduleSeries $scheduleSeries): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $scheduleSeries);
        $this->authorize('manageSchedule', $account);
        $this->ensureGroupClassesEnabled($account);

        $scheduleSeries->scheduledClasses()->delete();
        $scheduleSeries->delete();

        return redirect()->route('dashboard.accounts.schedule-series.index', $account)
            ->with('status', __('app.schedule_series_deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Account $account, ScheduleSeries $scheduleSeries): array
    {
        return [
            'account' => $account,
            'scheduleSeries' => $scheduleSeries,
            'locations' => $account->locations()->active()->orderBy('name')->get(),
            'rooms' => $account->rooms()->active()->with('location')->orderBy('name')->get(),
            'classTypes' => $account->classTypes()
                ->active()
                ->with('activityDirection')
                ->where('schedule_kind', ScheduleKind::GroupClass->value)
                ->orderBy('name')
                ->get(),
            'trainers' => $account->trainers()->active()->orderBy('name')->get(),
            'statuses' => ScheduleSeriesStatus::cases(),
            'weekdays' => $this->weekdays(),
        ];
    }

    private function ensureBelongsToAccount(Account $account, ScheduleSeries $scheduleSeries): void
    {
        abort_unless($scheduleSeries->account_id === $account->id, 404);
    }

    private function ensureGroupClassesEnabled(Account $account): void
    {
        abort_unless($account->hasScheduleKindEnabled(ScheduleKind::GroupClass), 404);
    }

    private function ensureRoomBelongsToLocation(Account $account, int $locationId, int $roomId): void
    {
        abort_unless($account->rooms()
            ->whereKey($roomId)
            ->where('location_id', $locationId)
            ->exists(), 422);
    }

    /**
     * @return array<int, string>
     */
    private function weekdays(): array
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
}
