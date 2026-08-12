<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\ReorderFestivalSchedule;
use App\Actions\Festivals\SaveFestivalScheduleSlot;
use App\Http\Requests\FestivalScheduleOrderRequest;
use App\Http\Requests\FestivalScheduleSlotRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FestivalScheduleController extends Controller
{
    public function store(FestivalScheduleSlotRequest $request, Account $account, FestivalEdition $festivalEdition, SaveFestivalScheduleSlot $save): RedirectResponse
    {
        $this->authorizeSchedule($request, $account, $festivalEdition);
        $slot = $save->execute($festivalEdition, $request->validated(), $request->user());

        return $this->redirect($account, $festivalEdition, $slot->festival_stage_id);
    }

    public function update(FestivalScheduleSlotRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalScheduleSlot $festivalScheduleSlot, SaveFestivalScheduleSlot $save): RedirectResponse
    {
        $this->authorizeSchedule($request, $account, $festivalEdition);
        abort_unless($festivalScheduleSlot->festival_edition_id === $festivalEdition->id, 404);
        $slot = $save->execute($festivalEdition, $request->validated(), $request->user(), $festivalScheduleSlot);

        return $this->redirect($account, $festivalEdition, $slot->festival_stage_id);
    }

    public function reorder(FestivalScheduleOrderRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage, ReorderFestivalSchedule $reorder): JsonResponse
    {
        $this->authorizeSchedule($request, $account, $festivalEdition);
        abort_unless($festivalStage->festival_edition_id === $festivalEdition->id && $festivalStage->account_id === $account->id, 404);
        $result = $reorder->execute($festivalEdition, $festivalStage, $request->validated('items'), $request->user());

        return response()->json([
            ...$result,
            'message' => __('app.festival_program_order_saved'),
        ]);
    }

    private function authorizeSchedule(Request $request, Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
        abort_unless($request->user()?->can('manageFestivalSchedule', $account), 403);
    }

    private function redirect(Account $account, FestivalEdition $edition, int $stageId): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.program', [
            'account' => $account,
            'festivalEdition' => $edition,
            'scene' => $stageId,
        ])->with('status', __('app.festival_schedule_saved'));
    }
}
