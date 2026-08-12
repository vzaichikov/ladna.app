<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FinalizeFestivalBattleMatch;
use App\Actions\Festivals\GenerateFestivalBattleBracket;
use App\Enums\FestivalCompetitionFormat;
use App\Http\Requests\FinalizeFestivalBattleMatchRequest;
use App\Http\Requests\GenerateFestivalBattleBracketRequest;
use App\Models\Account;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalBattleController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $categories = $festivalEdition->categories()
            ->where('competition_format', FestivalCompetitionFormat::Knockout->value)
            ->where('is_active', true)
            ->get();
        $matches = FestivalBattleMatch::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $festivalEdition->id)
            ->whereIn('festival_category_id', $categories->modelKeys())
            ->with(['entryA', 'entryB', 'winner'])
            ->withCount('votes')
            ->orderBy('round')
            ->orderBy('position')
            ->get()
            ->groupBy('festival_category_id');
        $judgeCounts = FestivalJudgeAssignment::query()
            ->where('festival_judge_assignments.account_id', $account->id)
            ->where('festival_judge_assignments.festival_edition_id', $festivalEdition->id)
            ->where('festival_judge_assignments.is_active', true)
            ->join('festival_category_judge_assignment as category_assignment', 'category_assignment.festival_judge_assignment_id', '=', 'festival_judge_assignments.id')
            ->whereIn('category_assignment.festival_category_id', $categories->modelKeys())
            ->selectRaw('category_assignment.festival_category_id, count(*) as aggregate')
            ->groupBy('category_assignment.festival_category_id')
            ->pluck('aggregate', 'festival_category_id');

        return view('festivals.staff.judging.battles', [
            'account' => $account,
            'edition' => $festivalEdition,
            'categories' => $categories,
            'matchesByCategory' => $matches,
            'judgeCounts' => $judgeCounts,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function generate(
        GenerateFestivalBattleBracketRequest $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalCategory $festivalCategory,
        GenerateFestivalBattleBracket $generate,
    ): RedirectResponse {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        $generate->execute($festivalEdition, $festivalCategory, $request->user(), $request->boolean('regenerate'));

        return back()->with('status', __('app.festival_battle_bracket_saved'));
    }

    public function finalize(
        FinalizeFestivalBattleMatchRequest $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalBattleMatch $festivalBattleMatch,
        FinalizeFestivalBattleMatch $finalize,
    ): RedirectResponse {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertMatch($account, $festivalEdition, $festivalBattleMatch);
        $data = $request->validated();
        $finalize->execute(
            $festivalBattleMatch,
            (int) $data['audience_votes_a'],
            (int) $data['audience_votes_b'],
            $request->user(),
            isset($data['tie_winner_entry_id']) ? (int) $data['tie_winner_entry_id'] : null,
            $data['tie_break_reason'] ?? null,
        );

        return back()->with('status', __('app.festival_battle_match_finalized'));
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function managerPermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        abort_unless($edition->account_id === $account->id, 404);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['manage'], 403);

        return $permissions;
    }

    private function assertCategory(Account $account, FestivalEdition $edition, FestivalCategory $category): void
    {
        abort_unless($category->account_id === $account->id && $category->festival_edition_id === $edition->id, 404);
    }

    private function assertMatch(Account $account, FestivalEdition $edition, FestivalBattleMatch $match): void
    {
        abort_unless($match->account_id === $account->id && $match->festival_edition_id === $edition->id, 404);
    }
}
