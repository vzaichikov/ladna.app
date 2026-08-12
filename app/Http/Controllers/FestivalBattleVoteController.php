<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\RecordFestivalBattleJudgeVote;
use App\Enums\FestivalBattleMatchStatus;
use App\Enums\FestivalPortalRole;
use App\Http\Requests\FestivalBattleJudgeVoteRequest;
use App\Models\Account;
use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalBattleVoteController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function indexStaff(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $assignment = $this->staffAssignment($request, $account, $festivalEdition);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $festivalEdition);

        return $this->votingView($account, $festivalEdition, $assignment, false, $permissions);
    }

    public function storeStaff(
        FestivalBattleJudgeVoteRequest $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalBattleMatch $festivalBattleMatch,
        RecordFestivalBattleJudgeVote $recordVote,
    ): RedirectResponse {
        $assignment = $this->staffAssignment($request, $account, $festivalEdition);
        $this->assertMatch($festivalEdition, $festivalBattleMatch);
        $recordVote->execute($festivalBattleMatch, $assignment, $request->integer('selected_entry_id'), $request->user());

        return back()->with('status', __('app.festival_battle_vote_saved'));
    }

    public function indexGuest(Request $request, string $accountSlug, string $editionSlug): View
    {
        [$account, $edition, $assignment] = $this->guestAssignment($request, $accountSlug, $editionSlug);

        return $this->votingView($account, $edition, $assignment, true);
    }

    public function storeGuest(
        FestivalBattleJudgeVoteRequest $request,
        string $accountSlug,
        string $editionSlug,
        FestivalBattleMatch $festivalBattleMatch,
        RecordFestivalBattleJudgeVote $recordVote,
    ): RedirectResponse {
        [, $edition, $assignment] = $this->guestAssignment($request, $accountSlug, $editionSlug);
        $this->assertMatch($edition, $festivalBattleMatch);
        $recordVote->execute($festivalBattleMatch, $assignment, $request->integer('selected_entry_id'), $request->user('festival'));

        return back()->with('status', __('app.festival_battle_vote_saved'));
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}|null  $permissions
     */
    private function votingView(
        Account $account,
        FestivalEdition $edition,
        FestivalJudgeAssignment $assignment,
        bool $guest,
        ?array $permissions = null,
    ): View {
        $categoryIds = $assignment->categories()->pluck('festival_categories.id');
        $matches = FestivalBattleMatch::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->whereIn('festival_category_id', $categoryIds)
            ->where('status', FestivalBattleMatchStatus::Ready->value)
            ->with(['category', 'entryA', 'entryB'])
            ->orderBy('round')
            ->orderBy('position')
            ->get();
        $votes = FestivalBattleJudgeVote::query()
            ->where('festival_judge_assignment_id', $assignment->id)
            ->whereIn('festival_battle_match_id', $matches->modelKeys())
            ->pluck('selected_entry_id', 'festival_battle_match_id');

        return view('festivals.shared.battle-voting', [
            'account' => $account,
            'edition' => $edition,
            'assignment' => $assignment,
            'matches' => $matches,
            'votes' => $votes,
            'guest' => $guest,
            'workspacePermissions' => $permissions,
        ]);
    }

    private function staffAssignment(Request $request, Account $account, FestivalEdition $edition): FestivalJudgeAssignment
    {
        abort_unless($edition->account_id === $account->id, 404);
        abort_unless($request->user()?->can('judgeFestivals', $account), 403);

        return FestivalJudgeAssignment::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /** @return array{Account, FestivalEdition, FestivalJudgeAssignment} */
    private function guestAssignment(Request $request, string $accountSlug, string $editionSlug): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $portalUser instanceof FestivalPortalUser && $portalUser->account_id === $account->id && $portalUser->role === FestivalPortalRole::Judge && $portalUser->is_active, 404);
        $edition = FestivalEdition::query()->whereBelongsTo($account)->where('slug', $editionSlug)->firstOrFail();
        $assignment = FestivalJudgeAssignment::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $edition->id)
            ->where('festival_portal_user_id', $portalUser->id)
            ->where('is_active', true)
            ->firstOrFail();

        return [$account, $edition, $assignment];
    }

    private function assertMatch(FestivalEdition $edition, FestivalBattleMatch $match): void
    {
        abort_unless($match->account_id === $edition->account_id && $match->festival_edition_id === $edition->id, 404);
    }
}
