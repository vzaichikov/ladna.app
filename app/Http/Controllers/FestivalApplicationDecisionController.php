<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FullyConfirmFestivalEntry;
use App\Actions\Festivals\FullyDeclineFestivalEntry;
use App\Enums\FestivalEntryStatus;
use App\Http\Requests\FullyConfirmFestivalEntryRequest;
use App\Http\Requests\FullyDeclineFestivalEntryRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\User;
use App\Support\Festivals\FestivalEntryFinalConfirmation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class FestivalApplicationDecisionController extends Controller
{
    public function preview(FullyConfirmFestivalEntryRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalEntry $festivalEntry, FestivalEntryFinalConfirmation $finalConfirmation): Response
    {
        $this->assertScope($account, $festivalEdition, $festivalEntry);
        abort_unless(in_array($festivalEntry->status, [FestivalEntryStatus::Submitted, FestivalEntryStatus::UnderReview, FestivalEntryStatus::ChangesPending], true), 409);

        return response()
            ->view('festivals.staff._application-full-confirmation', [
                'account' => $account,
                'edition' => $festivalEdition,
                'entry' => $festivalEntry,
                'finalConfirmationBlockers' => $finalConfirmation->blockers($festivalEntry),
            ])
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function confirm(FullyConfirmFestivalEntryRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalEntry $festivalEntry, FullyConfirmFestivalEntry $confirm): RedirectResponse
    {
        $this->assertScope($account, $festivalEdition, $festivalEntry);
        $reviewer = $request->user();
        abort_unless($reviewer instanceof User, 403);
        $confirm->execute($festivalEntry, $reviewer);

        return back()->with('status', __('app.festival_application_fully_confirmed'));
    }

    public function decline(FullyDeclineFestivalEntryRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalEntry $festivalEntry, FullyDeclineFestivalEntry $decline): RedirectResponse
    {
        $this->assertScope($account, $festivalEdition, $festivalEntry);
        $reviewer = $request->user();
        abort_unless($reviewer instanceof User, 403);
        $decline->execute($festivalEntry, $reviewer, $request->validated('reason'));

        return back()->with('status', __('app.festival_application_fully_declined'));
    }

    private function assertScope(Account $account, FestivalEdition $edition, FestivalEntry $entry): void
    {
        abort_unless($edition->account_id === $account->id
            && $entry->account_id === $account->id
            && $entry->festival_edition_id === $edition->id, 404);
    }
}
