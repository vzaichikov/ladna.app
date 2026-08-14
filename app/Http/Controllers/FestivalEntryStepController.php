<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\ReassignFestivalEntryCategory;
use App\Actions\Festivals\ReviewFestivalEntryStep;
use App\Actions\Festivals\StoreFestivalResponse;
use App\Actions\Festivals\SubmitFestivalEntryStep;
use App\Enums\FestivalEntryStepStatus;
use App\Http\Requests\FestivalEntryCategoryReassignmentRequest;
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
use Illuminate\Http\JsonResponse;
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

    public function storeResponse(FestivalEntryStepRequest $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalEntryStep $festivalEntryStep, FestivalEntryRequirement $festivalEntryRequirement, StoreFestivalResponse $store, FestivalEntryWorkflowState $workflowState): JsonResponse|RedirectResponse
    {
        [, $portalUser] = $this->portalContext($request, $accountSlug);
        $this->assertPortalEntry($festivalEntry, $festivalEntryStep, $portalUser);
        abort_unless($festivalEntryRequirement->festival_entry_step_id === $festivalEntryStep->id, 404);
        $store->execute($festivalEntryRequirement, $portalUser, $request->input('value'));

        if ($request->expectsJson()) {
            $festivalEntryRequirement->refresh()->load(['definition', 'participant', 'submissions']);
            $festivalEntry->refresh()->load($this->entryRelations());
            $selectedStep = $festivalEntry->steps->firstWhere('id', $festivalEntryStep->id);
            $selectedState = $workflowState->forEntry($festivalEntry)->first(fn (array $state): bool => $state['step']->is($selectedStep));

            return response()->json([
                'message' => __('app.festival_response_saved'),
                'requirement_id' => $festivalEntryRequirement->id,
                'requirement_html' => view('festivals.portal._requirement-card', [
                    'account' => $request->attributes->get('festivalAccount'),
                    'portalUser' => $portalUser,
                    'entry' => $festivalEntry,
                    'selectedStep' => $selectedStep,
                    'selectedState' => $selectedState,
                    'requirement' => $festivalEntryRequirement,
                ])->render(),
            ]);
        }

        return back()->with('status', __('app.festival_response_saved'));
    }

    public function submit(Request $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalEntryStep $festivalEntryStep, SubmitFestivalEntryStep $submit): RedirectResponse
    {
        [, $portalUser] = $this->portalContext($request, $accountSlug);
        $this->assertPortalEntry($festivalEntry, $festivalEntryStep, $portalUser);
        $submit->execute($festivalEntry, $festivalEntryStep);

        return redirect()->route('festival.portal.entries.show', [$accountSlug, $festivalEntry])->with('status', __('app.festival_step_submitted'));
    }

    public function review(FestivalEntryStepReviewRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalEntry $festivalEntry, FestivalEntryStep $festivalEntryStep, ReviewFestivalEntryStep $review): JsonResponse|RedirectResponse
    {
        abort_unless($festivalEdition->account_id === $account->id && $festivalEntry->festival_edition_id === $festivalEdition->id && $festivalEntryStep->festival_entry_id === $festivalEntry->id, 404);
        $data = $request->validated();
        $review->execute($festivalEntryStep, $request->user(), $data['decision'], $data['comment'] ?? null, $data['correction_due_at'] ?? null, $data['requirement_notes'] ?? []);

        if ($request->expectsJson()) {
            $festivalEntry->refresh()->load('steps.workflowStep');
            $currentStep = $festivalEntry->steps->first(
                fn (FestivalEntryStep $step): bool => $step->status !== FestivalEntryStepStatus::Approved,
            );
            $fragments = [
                view('festivals.staff._application-step-review', [
                    'account' => $account,
                    'edition' => $festivalEdition,
                    'entry' => $festivalEntry,
                    'currentStep' => $currentStep,
                ])->render(),
            ];

            if ($request->user()?->can('manageFestivalFinance', $account)) {
                $festivalEntry->load('charges.paymentAttempts.fiscalReceipt');
                $fragments[] = view('festivals.staff._application-charges', [
                    'account' => $account,
                    'edition' => $festivalEdition,
                    'entry' => $festivalEntry,
                ])->render();
            }

            return response()->json([
                'message' => __('app.festival_step_reviewed'),
                'fragments_html' => $fragments,
            ]);
        }

        return back()->with('status', __('app.festival_step_reviewed'));
    }

    public function reassignCategory(FestivalEntryCategoryReassignmentRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalEntry $festivalEntry, ReassignFestivalEntryCategory $reassign): JsonResponse|RedirectResponse
    {
        abort_unless($festivalEdition->account_id === $account->id && $festivalEntry->festival_edition_id === $festivalEdition->id, 404);
        $data = $request->validated();
        $category = $festivalEdition->categories()->whereKey($data['festival_category_id'])->firstOrFail();
        $festivalEntry = $reassign->execute($festivalEntry, $category, $request->user(), $data['reason']);

        if ($request->expectsJson()) {
            $festivalEntry->load('category.direction');
            $fragments = [
                view('festivals.staff._application-category-review', [
                    'account' => $account,
                    'edition' => $festivalEdition,
                    'entry' => $festivalEntry,
                    'categories' => $festivalEdition->categories()->with('direction')->orderBy('name')->get(),
                    'canManageRegistrations' => true,
                ])->render(),
            ];

            if ($request->user()?->can('manageFestivalFinance', $account)) {
                $festivalEntry->load('charges.paymentAttempts.fiscalReceipt');
                $fragments[] = view('festivals.staff._application-charges', [
                    'account' => $account,
                    'edition' => $festivalEdition,
                    'entry' => $festivalEntry,
                ])->render();
            }

            return response()->json([
                'message' => __('app.festival_category_reassigned'),
                'fragments_html' => $fragments,
            ]);
        }

        return back()->with('status', __('app.festival_category_reassigned'));
    }

    /** @return array<int|string, mixed> */
    private function entryRelations(): array
    {
        return [
            'edition',
            'category.direction',
            'participants',
            'steps.workflowStep',
            'steps.requirements.definition',
            'steps.requirements.participant',
            'steps.requirements.submissions',
            'steps.charges.paymentAttempts',
            'chargeAdjustments',
            'scheduleSlots' => fn ($query) => $query
                ->whereNotNull('published_at')
                ->whereNotNull('starts_at')
                ->whereNotNull('ends_at')
                ->with('stage')
                ->orderBy('starts_at'),
            'result',
            'scoreSheets.assignment',
            'scoreSheets.scores.criterion.section',
        ];
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
