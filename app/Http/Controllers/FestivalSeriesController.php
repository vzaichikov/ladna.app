<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalSeries;
use App\Http\Requests\FestivalSeriesRequest;
use App\Models\Account;
use App\Models\FestivalSeries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalSeriesController extends Controller
{
    public function create(Request $request, Account $account): View
    {
        abort_unless($request->user()?->can('manageFestivals', $account), 403);

        return view('festivals.staff.series-form', [
            'account' => $account,
            'series' => new FestivalSeries,
        ]);
    }

    public function store(FestivalSeriesRequest $request, Account $account, SaveFestivalSeries $save): RedirectResponse
    {
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $save->execute($account, $request->validated(), $request->user());

        return redirect()->route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'series',
        ])->with('status', __('app.festival_series_saved'));
    }

    public function edit(Request $request, Account $account, FestivalSeries $festivalSeries): View
    {
        $this->assertSeries($account, $festivalSeries);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);

        return view('festivals.staff.series-form', [
            'account' => $account,
            'series' => $festivalSeries,
            'telegramInstallation' => $festivalSeries->telegramBotInstallation,
            'canManageTelegramToken' => (bool) $request->user()?->can('manageStudioSettings', $account),
        ]);
    }

    public function update(FestivalSeriesRequest $request, Account $account, FestivalSeries $festivalSeries, SaveFestivalSeries $save): RedirectResponse
    {
        $this->assertSeries($account, $festivalSeries);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $save->execute($account, $request->validated(), $request->user(), $festivalSeries);

        return redirect()->route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'series',
        ])->with('status', __('app.festival_series_saved'));
    }

    private function assertSeries(Account $account, FestivalSeries $series): void
    {
        abort_unless($series->account_id === $account->id, 404);
    }
}
