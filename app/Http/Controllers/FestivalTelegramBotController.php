<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFestivalTelegramBotRequest;
use App\Models\Account;
use App\Models\FestivalSeries;
use App\Support\Telegram\FestivalTelegramBotConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FestivalTelegramBotController extends Controller
{
    public function update(UpdateFestivalTelegramBotRequest $request, Account $account, FestivalSeries $festivalSeries, FestivalTelegramBotConnector $connector): RedirectResponse
    {
        return $this->redirect($account, $festivalSeries, $connector->connect(
            $account,
            $festivalSeries,
            $request->validated('token'),
        ));
    }

    public function reconnect(Request $request, Account $account, FestivalSeries $festivalSeries, FestivalTelegramBotConnector $connector): RedirectResponse
    {
        $this->authorizeSeries($request, $account, $festivalSeries);

        return $this->redirect($account, $festivalSeries, $connector->reconnect($account, $festivalSeries));
    }

    public function check(Request $request, Account $account, FestivalSeries $festivalSeries, FestivalTelegramBotConnector $connector): RedirectResponse
    {
        $this->authorizeSeries($request, $account, $festivalSeries);

        return $this->redirect($account, $festivalSeries, $connector->check($account, $festivalSeries));
    }

    public function disable(Request $request, Account $account, FestivalSeries $festivalSeries, FestivalTelegramBotConnector $connector): RedirectResponse
    {
        $this->authorizeSeries($request, $account, $festivalSeries);

        return $this->redirect($account, $festivalSeries, $connector->disable($account, $festivalSeries));
    }

    public function destroy(Request $request, Account $account, FestivalSeries $festivalSeries, FestivalTelegramBotConnector $connector): RedirectResponse
    {
        $this->authorizeSeries($request, $account, $festivalSeries, requireStudioSettings: true);

        return $this->redirect($account, $festivalSeries, $connector->disconnect($account, $festivalSeries));
    }

    private function authorizeSeries(Request $request, Account $account, FestivalSeries $series, bool $requireStudioSettings = false): void
    {
        abort_unless((int) $series->account_id === (int) $account->id, 404);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);

        if ($requireStudioSettings) {
            abort_unless($request->user()?->can('manageStudioSettings', $account), 403);
        }
    }

    /** @param array{ok: bool, message: string} $result */
    private function redirect(Account $account, FestivalSeries $series, array $result): RedirectResponse
    {
        $redirect = redirect()->route('dashboard.accounts.festivals.series.edit', [$account, $series]);

        return $result['ok']
            ? $redirect->with('status', $result['message'])
            : $redirect->withErrors(['festival_telegram_bot' => $result['message']]);
    }
}
