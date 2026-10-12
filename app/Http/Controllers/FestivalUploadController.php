<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\StoreFestivalSubmission;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalRequirementInputType;
use App\Http\Requests\FestivalSubmissionRequest;
use App\Models\Account;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalEntryWorkflowState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class FestivalUploadController extends Controller
{
    public function authorizeUpload(Request $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalEntryRequirement $festivalEntryRequirement, FestivalEntryWorkflowState $workflowState): JsonResponse
    {
        abort_unless(filled(config('festival_uploads.url')), 404);
        $portalUser = $this->portalUser($request, $accountSlug, $festivalEntry, $festivalEntryRequirement);
        $festivalEntryRequirement->load(['definition', 'entry.edition', 'entry.steps.workflowStep', 'entryStep.workflowStep']);
        abort_unless($festivalEntryRequirement->definition->input_type === FestivalRequirementInputType::File, 422);
        if ($festivalEntryRequirement->entryStep) {
            $workflowState->assertRequirementMutable($festivalEntryRequirement);
        } else {
            abort_unless($festivalEntry->steps()->doesntExist(), 409);
        }

        return response()->json(['upload_url' => URL::temporarySignedRoute('festival.uploads.store', now()->addHour(), [
            'accountSlug' => $accountSlug,
            'festivalEntry' => $festivalEntry,
            'festivalEntryRequirement' => $festivalEntryRequirement,
            'portal_user_id' => $portalUser->id,
            'locale' => app()->getLocale(),
        ])]);
    }

    public function store(FestivalSubmissionRequest $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalEntryRequirement $festivalEntryRequirement, StoreFestivalSubmission $store): JsonResponse
    {
        $portalUser = $this->portalUser($request, $accountSlug, $festivalEntry, $festivalEntryRequirement);
        $wasAccepted = $festivalEntry->status === FestivalEntryStatus::Accepted;
        $submission = $store->execute($festivalEntryRequirement, $portalUser, $request->file('file'));

        return response()->json([
            'message' => __('app.festival_submission_saved'),
            'requirement_id' => $festivalEntryRequirement->id,
            'submission_id' => $submission->id,
            'reload' => $wasAccepted && $festivalEntry->refresh()->status === FestivalEntryStatus::ChangesPending,
        ]);
    }

    private function portalUser(Request $request, string $accountSlug, FestivalEntry $entry, FestivalEntryRequirement $requirement): FestivalPortalUser
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $portalUser instanceof FestivalPortalUser, 404);
        abort_unless($entry->account_id === $account->id && $entry->festival_portal_user_id === $portalUser->id
            && $requirement->account_id === $account->id && $requirement->festival_entry_id === $entry->id, 404);

        return $portalUser;
    }
}
