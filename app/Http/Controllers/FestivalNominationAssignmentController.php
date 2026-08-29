<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SyncFestivalNominationParticipants;
use App\Enums\FestivalEntryStatus;
use App\Http\Requests\FestivalNominationAssignmentRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalNomination;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalResultTableAccess;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class FestivalNominationAssignmentController extends Controller
{
    public function __construct(
        private readonly FestivalResultTableAccess $access,
        private readonly FestivalWorkspaceAccess $workspaceAccess,
    ) {}

    public function indexStaff(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($this->access->canStaffView($request->user(), $account, $festivalEdition), 403);
        $assignment = $this->access->staffAssignment($request->user(), $festivalEdition);

        return view('festivals.staff.judging.nominations', $this->pageData(
            $request,
            $account,
            $festivalEdition,
            $assignment,
            $this->access->canStaffEdit($request->user(), $account, $festivalEdition),
            false,
        ) + ['workspacePermissions' => $this->workspaceAccess->permissions($request->user(), $account, $festivalEdition)]);
    }

    public function updateStaff(FestivalNominationAssignmentRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalNomination $festivalNomination, SyncFestivalNominationParticipants $sync): JsonResponse|RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($this->access->canStaffEdit($request->user(), $account, $festivalEdition), 403);
        $this->assertNomination($account, $festivalEdition, $festivalNomination);
        abort_unless($festivalNomination->is_active, 422);
        $assignment = $this->access->staffAssignment($request->user(), $festivalEdition);
        $sync->execute($festivalNomination, $this->eligibleParticipantIds($festivalEdition, $assignment), $request->validated('participant_ids', []), $request->user());

        return $this->savedResponse($request);
    }

    public function indexPortal(Request $request, string $accountSlug, FestivalEdition $festivalEdition): View
    {
        [$account, $portalUser, $assignment] = $this->portalContext($request, $accountSlug, $festivalEdition);

        return view('festivals.portal.nominations', $this->pageData($request, $account, $festivalEdition, $assignment, true, true) + [
            'portalUser' => $portalUser,
        ]);
    }

    public function updatePortal(FestivalNominationAssignmentRequest $request, string $accountSlug, FestivalEdition $festivalEdition, FestivalNomination $festivalNomination, SyncFestivalNominationParticipants $sync): JsonResponse|RedirectResponse
    {
        [$account, $portalUser, $assignment] = $this->portalContext($request, $accountSlug, $festivalEdition);
        $this->assertNomination($account, $festivalEdition, $festivalNomination);
        abort_unless($festivalNomination->is_active, 422);
        $sync->execute($festivalNomination, $this->eligibleParticipantIds($festivalEdition, $assignment), $request->validated('participant_ids', []), $portalUser);

        return $this->savedResponse($request);
    }

    /** @return array<string, mixed> */
    private function pageData(Request $request, Account $account, FestivalEdition $edition, ?FestivalJudgeAssignment $assignment, bool $editable, bool $guest): array
    {
        $participants = $this->eligibleParticipants($edition, $assignment);
        $visibleParticipantIds = $participants->modelKeys();
        $query = mb_strtolower($request->string('q')->trim()->toString());
        $participantRows = $query === '' ? $participants : $participants->filter(
            fn (FestivalParticipant $participant): bool => str_contains(mb_strtolower($participant->displayName()), $query),
        )->values();
        $categories = $edition->categories()
            ->when($assignment, fn ($builder) => $builder->whereIn('id', $assignment->categories->modelKeys()))
            ->get();
        $nominations = $edition->nominations()->with(['participants' => fn ($builder) => $builder
            ->when($assignment, fn ($query) => $query->whereKey($visibleParticipantIds))
            ->orderBy('last_name')
            ->orderBy('first_name')])->get();

        return compact('account', 'edition', 'assignment', 'editable', 'guest', 'participants', 'participantRows', 'categories', 'nominations') + [
            'filters' => ['q' => $request->string('q')->trim()->toString()],
        ];
    }

    /** @return Collection<int, FestivalParticipant> */
    private function eligibleParticipants(FestivalEdition $edition, ?FestivalJudgeAssignment $assignment): Collection
    {
        $categoryIds = $assignment?->categories->modelKeys();

        return FestivalParticipant::query()
            ->active()
            ->performers()
            ->where('account_id', $edition->account_id)
            ->whereHas('entries', fn ($query) => $query
                ->where('festival_edition_id', $edition->id)
                ->where('status', FestivalEntryStatus::Accepted->value)
                ->when($categoryIds !== null, fn ($builder) => $builder->whereIn('festival_category_id', $categoryIds)))
            ->with([
                'entries' => fn ($query) => $query
                    ->where('festival_edition_id', $edition->id)
                    ->where('status', FestivalEntryStatus::Accepted->value)
                    ->when($categoryIds !== null, fn ($builder) => $builder->whereIn('festival_category_id', $categoryIds))
                    ->with('category'),
                'nominations' => fn ($query) => $query->where('festival_edition_id', $edition->id),
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /** @return Collection<int, int> */
    private function eligibleParticipantIds(FestivalEdition $edition, ?FestivalJudgeAssignment $assignment): Collection
    {
        return collect($this->eligibleParticipants($edition, $assignment)->modelKeys());
    }

    /** @return array{Account, FestivalPortalUser, FestivalJudgeAssignment} */
    private function portalContext(Request $request, string $accountSlug, FestivalEdition $edition): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $portalUser instanceof FestivalPortalUser, 404);
        $this->assertEdition($account, $edition);
        $assignment = $this->access->portalAssignment($portalUser, $edition);
        abort_unless($assignment, 403);

        return [$account, $portalUser, $assignment];
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertNomination(Account $account, FestivalEdition $edition, FestivalNomination $nomination): void
    {
        abort_unless($nomination->account_id === $account->id && $nomination->festival_edition_id === $edition->id, 404);
    }

    private function savedResponse(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => __('app.festival_nomination_assignments_saved'), 'reload' => true]);
        }

        return back()->with('status', __('app.festival_nomination_assignments_saved'));
    }
}
