<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalScheduleSlot;
use App\Http\Requests\FestivalScheduleSlotRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalScheduleSlot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FestivalScheduleController extends Controller
{
    public function store(FestivalScheduleSlotRequest $request, Account $account, FestivalEdition $festivalEdition, SaveFestivalScheduleSlot $save): RedirectResponse
    {
        $this->authorizeSchedule($request, $account, $festivalEdition);
        $save->execute($festivalEdition, $request->validated(), $request->user());

        return redirect()->route('dashboard.accounts.festivals.program', [$account, $festivalEdition])->with('status', __('app.festival_schedule_saved'));
    }

    public function update(FestivalScheduleSlotRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalScheduleSlot $festivalScheduleSlot, SaveFestivalScheduleSlot $save): RedirectResponse
    {
        $this->authorizeSchedule($request, $account, $festivalEdition);
        abort_unless($festivalScheduleSlot->festival_edition_id === $festivalEdition->id, 404);
        $save->execute($festivalEdition, $request->validated(), $request->user(), $festivalScheduleSlot);

        return redirect()->route('dashboard.accounts.festivals.program', [$account, $festivalEdition])->with('status', __('app.festival_schedule_saved'));
    }

    private function authorizeSchedule(Request $request, Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
        abort_unless($request->user()?->can('manageFestivalSchedule', $account), 403);
    }
}
