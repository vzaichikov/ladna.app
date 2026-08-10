<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\ReviewFestivalEntryStep;
use App\Actions\Festivals\StoreFestivalResponse;
use App\Actions\Festivals\SubmitFestivalEntryStep;
use App\Http\Requests\FestivalEntryStepRequest;
use App\Http\Requests\FestivalEntryStepReviewRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalEntryStep;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalEntryWorkflowState;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalEntryStepController extends Controller
{
    public function show(Request $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalEntryStep $festivalEntryStep, FestivalEntryWorkflowState $workflowState): View
    {
        [$account, $portalUser] = $this->portalContext($request, $accountSlug);
        $this->assertPortalEntry($festivalEntry, $festivalEntryStep, $portalUser);
        $festivalEntry->load($this->entryRelations());
        $selectedStep = $festivalEntry->steps->firstWhere('id', $festivalEntryStep->id);

        return view('festivals.portal.entry', [
            'account' => $account,
            'portalUser' => $portalUser,
            'entry' => $festivalEntry,
            'providers' => app(PaymentGatewayRegistry::class)->availableSettingsFor($account),
            'workflowStates' => $workflowState->forEntry($festivalEntry),
            'selectedStep' => $selectedStep,
        ]);
    }

    public function storeResponse(FestivalEntryStepRequest $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalEntryStep $festivalEntryStep, FestivalEntryRequirement $festivalEntryRequirement, StoreFestivalResponse $store): RedirectResponse
    {
        [, $portalUser] = $this->portalContext($request, $accountSlug);
        $this->assertPortalEntry($festivalEntry, $festivalEntryStep, $portalUser);
        abort_unless($festivalEntryRequirement->festival_entry_step_id === $festivalEntryStep->id, 404);
        $store->execute($festivalEntryRequirement, $portalUser, $request->input('value'));

        return back()->with('status', __('app.festival_response_saved'));
    }

    public function submit(Request $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalEntryStep $festivalEntryStep, SubmitFestivalEntryStep $submit): RedirectResponse
    {
        [, $portalUser] = $this->portalContext($request, $accountSlug);
        $this->assertPortalEntry($festivalEntry, $festivalEntryStep, $portalUser);
        $submit->execute($festivalEntry, $festivalEntryStep);

        return redirect()->route('festival.portal.entries.show', [$accountSlug, $festivalEntry])->with('status', __('app.festival_step_submitted'));
    }

    public function review(FestivalEntryStepReviewRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalEntry $festivalEntry, FestivalEntryStep $festivalEntryStep, ReviewFestivalEntryStep $review): RedirectResponse
    {
        abort_unless($festivalEdition->account_id === $account->id && $festivalEntry->festival_edition_id === $festivalEdition->id && $festivalEntryStep->festival_entry_id === $festivalEntry->id, 404);
        $data = $request->validated();
        $review->execute($festivalEntryStep, $request->user(), $data['decision'], $data['comment'] ?? null, $data['revision_due_at'] ?? null, $data['requirement_notes'] ?? []);

        return back()->with('status', __('app.festival_step_reviewed'));
    }

    /** @return array<int, string> */
    private function entryRelations(): array
    {
        return ['edition', 'category.options.axis', 'participants', 'steps.requirements.submissions', 'steps.charges.paymentAttempts', 'chargeAdjustments', 'scheduleSlots.stage', 'result', 'scoreSheets.assignment', 'scoreSheets.scores.criterion.section'];
    }

    /** @return array{Account, FestivalPortalUser} */
    private function portalContext(Request $request, string $slug): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $slug && $portalUser instanceof FestivalPortalUser && $portalUser->account_id === $account->id, 404);

        return [$account, $portalUser];
    }

    private function assertPortalEntry(FestivalEntry $entry, FestivalEntryStep $step, FestivalPortalUser $portalUser): void
    {
        abort_unless($entry->festival_portal_user_id === $portalUser->id && $entry->account_id === $portalUser->account_id && $step->festival_entry_id === $entry->id, 404);
    }
}
