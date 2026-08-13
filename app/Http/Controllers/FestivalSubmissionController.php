<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\StoreFestivalSubmission;
use App\Http\Requests\FestivalSubmissionRequest;
use App\Models\Account;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalEntryWorkflowState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class FestivalSubmissionController extends Controller
{
    public function store(FestivalSubmissionRequest $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalEntryRequirement $festivalEntryRequirement, StoreFestivalSubmission $store, FestivalEntryWorkflowState $workflowState): JsonResponse|RedirectResponse
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $portalUser instanceof FestivalPortalUser, 404);
        abort_unless($festivalEntry->account_id === $account->id && $festivalEntry->festival_portal_user_id === $portalUser->id && $festivalEntryRequirement->festival_entry_id === $festivalEntry->id, 404);
        $store->execute($festivalEntryRequirement, $portalUser, $request->file('file'));

        if ($request->expectsJson()) {
            $festivalEntryRequirement->refresh()->load(['definition', 'participant', 'submissions', 'entryStep']);
            $festivalEntry->refresh()->load(['edition', 'steps.workflowStep', 'steps.requirements.definition.edition', 'steps.requirements.submissions', 'steps.charges']);
            $selectedStep = $festivalEntry->steps->firstWhere('id', $festivalEntryRequirement->festival_entry_step_id);
            $selectedState = $workflowState->forEntry($festivalEntry)->first(fn (array $state): bool => $state['step']->is($selectedStep));

            return response()->json([
                'message' => __('app.festival_submission_saved'),
                'requirement_id' => $festivalEntryRequirement->id,
                'requirement_html' => view('festivals.portal._requirement-card', [
                    'account' => $account,
                    'portalUser' => $portalUser,
                    'entry' => $festivalEntry,
                    'selectedStep' => $selectedStep,
                    'selectedState' => $selectedState,
                    'requirement' => $festivalEntryRequirement,
                ])->render(),
            ]);
        }

        return back()->with('status', __('app.festival_submission_saved'));
    }
}
