<?php

namespace Tests\Feature;

use App\Actions\Festivals\FinalizeFestivalBattleMatch;
use App\Actions\Festivals\GenerateFestivalBattleBracket;
use App\Actions\Festivals\RecordFestivalBattleJudgeVote;
use App\Enums\FestivalBattleMatchStatus;
use App\Models\Account;
use App\Models\FestivalActivityLog;
use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FestivalBattleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bracket_uses_only_accepted_paid_entries_supports_byes_and_audits_draw_order(): void
    {
        [$account, $edition, $category] = $this->festival();
        $actor = User::factory()->create();
        $eligibleEntries = collect(range(1, 5))->map(fn (): FestivalEntry => $this->entry($category, true, 'accepted'));
        $this->entry($category, false, 'accepted');
        $this->entry($category, true, 'submitted');

        $matches = app(GenerateFestivalBattleBracket::class)->execute($edition, $category, $actor);

        $this->assertCount(7, $matches);
        $firstRound = $matches->where('round', 1);
        $drawnEntryIds = $firstRound
            ->flatMap(fn (FestivalBattleMatch $match): array => [$match->entry_a_id, $match->entry_b_id])
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $eligibleEntryIds = $eligibleEntries->pluck('id')->sort()->values();
        $this->assertSame($eligibleEntryIds->all(), $drawnEntryIds->all());
        $this->assertSame(3, $firstRound->where('status', FestivalBattleMatchStatus::Completed)->count());
        $this->assertSame(1, $firstRound->where('status', FestivalBattleMatchStatus::Ready)->count());

        $activity = FestivalActivityLog::query()
            ->where('account_id', $account->id)
            ->where('action', 'battle.bracket_generated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($eligibleEntryIds->all(), collect($activity->payload['entry_ids'])->sort()->values()->all());
        $this->assertSame(8, $activity->payload['bracket_size']);
    }

    public function test_generation_enforces_category_minimum_and_regeneration_locks_after_voting(): void
    {
        [$account, $edition, $category] = $this->festival();
        $actor = User::factory()->create();
        collect(range(1, 4))->each(fn (): FestivalEntry => $this->entry($category, true, 'accepted'));

        try {
            app(GenerateFestivalBattleBracket::class)->execute($edition, $category, $actor);
            $this->fail('A bracket was generated below the category minimum.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('festival_battle_matches', ['festival_category_id' => $category->id]);
        }

        $this->entry($category, true, 'accepted');
        $firstGeneration = app(GenerateFestivalBattleBracket::class)->execute($edition, $category, $actor);
        $firstIds = $firstGeneration->modelKeys();
        $secondGeneration = app(GenerateFestivalBattleBracket::class)->execute($edition, $category, $actor, true);
        $this->assertEmpty(array_intersect($firstIds, $secondGeneration->modelKeys()));

        [$judge, $assignment] = $this->judge($account, $edition, $category);
        $readyMatch = $secondGeneration->first(fn (FestivalBattleMatch $match): bool => $match->status === FestivalBattleMatchStatus::Ready);
        app(RecordFestivalBattleJudgeVote::class)->execute($readyMatch, $assignment, $readyMatch->entry_a_id, $judge);

        $this->expectException(ValidationException::class);
        app(GenerateFestivalBattleBracket::class)->execute($edition, $category, $actor, true);
    }

    public function test_judge_vote_is_assignment_scoped_and_can_change_before_finalization(): void
    {
        [$account, $edition, $category] = $this->festival();
        $match = $this->readyMatch($edition, $category);
        [$judge, $assignment] = $this->judge($account, $edition, $category);

        app(RecordFestivalBattleJudgeVote::class)->execute($match, $assignment, $match->entry_a_id, $judge);
        app(RecordFestivalBattleJudgeVote::class)->execute($match, $assignment, $match->entry_b_id, $judge);

        $this->assertSame(1, FestivalBattleJudgeVote::query()->where('festival_battle_match_id', $match->id)->count());
        $this->assertSame($match->entry_b_id, FestivalBattleJudgeVote::query()->where('festival_battle_match_id', $match->id)->value('selected_entry_id'));

        $assignment->update(['is_active' => false]);

        try {
            app(RecordFestivalBattleJudgeVote::class)->execute($match, $assignment, $match->entry_a_id, $judge);
            $this->fail('An inactive judge changed a Battle vote.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_finalization_requires_every_active_judge_and_audience_votes_then_advances_winner(): void
    {
        [$account, $edition, $category] = $this->festival();
        collect(range(1, 5))->each(fn (): FestivalEntry => $this->entry($category, true, 'accepted'));
        $actor = User::factory()->create();
        $matches = app(GenerateFestivalBattleBracket::class)->execute($edition, $category, $actor);
        $match = $matches->where('round', 1)->first(fn (FestivalBattleMatch $candidate): bool => $candidate->status === FestivalBattleMatchStatus::Ready);
        $judges = collect(range(1, 4))->map(fn (): array => $this->judge($account, $edition, $category));

        app(RecordFestivalBattleJudgeVote::class)->execute($match, $judges[0][1], $match->entry_a_id, $judges[0][0]);
        app(RecordFestivalBattleJudgeVote::class)->execute($match, $judges[1][1], $match->entry_a_id, $judges[1][0]);

        try {
            app(FinalizeFestivalBattleMatch::class)->execute($match, 10, 30, $actor);
            $this->fail('A Battle match was finalized with an active judge vote missing.');
        } catch (ValidationException) {
            $this->assertSame(FestivalBattleMatchStatus::Ready, $match->refresh()->status);
        }

        app(RecordFestivalBattleJudgeVote::class)->execute($match, $judges[2][1], $match->entry_b_id, $judges[2][0]);

        try {
            app(FinalizeFestivalBattleMatch::class)->execute($match, 20, 30, $actor);
            $this->fail('A Battle match was finalized with only three of four judge votes.');
        } catch (ValidationException) {
            $this->assertSame(FestivalBattleMatchStatus::Ready, $match->refresh()->status);
        }

        app(RecordFestivalBattleJudgeVote::class)->execute($match, $judges[3][1], $match->entry_a_id, $judges[3][0]);

        try {
            app(FinalizeFestivalBattleMatch::class)->execute($match, 0, 0, $actor);
            $this->fail('A Battle match was finalized without audience votes.');
        } catch (ValidationException) {
            $this->assertSame(FestivalBattleMatchStatus::Ready, $match->refresh()->status);
        }

        $finalized = app(FinalizeFestivalBattleMatch::class)->execute($match, 20, 30, $actor);

        $this->assertSame(FestivalBattleMatchStatus::Completed, $finalized->status);
        $this->assertSame($match->entry_a_id, $finalized->winner_entry_id);
        $this->assertSame('75.0000', $finalized->jury_percentage_a);
        $this->assertSame('40.0000', $finalized->audience_percentage_a);
        $this->assertSame('57.5000', $finalized->combined_percentage_a);
        $this->assertSame('42.5000', $finalized->combined_percentage_b);
        $nextMatch = FestivalBattleMatch::query()->findOrFail($match->next_match_id);
        $this->assertSame(FestivalBattleMatchStatus::Ready, $nextMatch->status);
        $this->assertContains($finalized->winner_entry_id, [$nextMatch->entry_a_id, $nextMatch->entry_b_id]);
    }

    public function test_exact_combined_tie_requires_manager_winner_and_reason(): void
    {
        [$account, $edition, $category] = $this->festival();
        $actor = User::factory()->create();
        $match = $this->readyMatch($edition, $category);
        $judges = collect(range(1, 4))->map(fn (): array => $this->judge($account, $edition, $category));
        app(RecordFestivalBattleJudgeVote::class)->execute($match, $judges[0][1], $match->entry_a_id, $judges[0][0]);
        app(RecordFestivalBattleJudgeVote::class)->execute($match, $judges[1][1], $match->entry_a_id, $judges[1][0]);
        app(RecordFestivalBattleJudgeVote::class)->execute($match, $judges[2][1], $match->entry_b_id, $judges[2][0]);
        app(RecordFestivalBattleJudgeVote::class)->execute($match, $judges[3][1], $match->entry_b_id, $judges[3][0]);

        try {
            app(FinalizeFestivalBattleMatch::class)->execute($match, 10, 10, $actor);
            $this->fail('A tied Battle match was finalized without a jury decision.');
        } catch (ValidationException) {
            $this->assertSame(FestivalBattleMatchStatus::Ready, $match->refresh()->status);
        }

        $finalized = app(FinalizeFestivalBattleMatch::class)->execute(
            $match,
            10,
            10,
            $actor,
            $match->entry_a_id,
            'Final jury decision based on the overall impression.',
        );

        $this->assertSame($match->entry_a_id, $finalized->winner_entry_id);
        $this->assertSame('50.0000', $finalized->combined_percentage_a);
        $this->assertSame('50.0000', $finalized->combined_percentage_b);
        $this->assertSame('Final jury decision based on the overall impression.', $finalized->tie_break_reason);
        $this->assertSame($actor->id, $finalized->decided_by);
    }

    public function test_finalization_requires_exactly_four_active_category_judges(): void
    {
        [$account, $edition, $category] = $this->festival();
        $actor = User::factory()->create();
        $match = $this->readyMatch($edition, $category);
        $judges = collect(range(1, 3))->map(fn (): array => $this->judge($account, $edition, $category));

        foreach ($judges as [$judge, $assignment]) {
            app(RecordFestivalBattleJudgeVote::class)->execute($match, $assignment, $match->entry_a_id, $judge);
        }

        try {
            app(FinalizeFestivalBattleMatch::class)->execute($match, 10, 5, $actor);
            $this->fail('A Battle match was finalized with fewer than four judges.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('match', $exception->errors());
        }

        foreach (range(1, 2) as $unused) {
            [$judge, $assignment] = $this->judge($account, $edition, $category);
            app(RecordFestivalBattleJudgeVote::class)->execute($match, $assignment, $match->entry_a_id, $judge);
        }

        try {
            app(FinalizeFestivalBattleMatch::class)->execute($match, 10, 5, $actor);
            $this->fail('A Battle match was finalized with more than four judges.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('match', $exception->errors());
        }
    }

    public function test_vote_rejects_an_assignment_from_another_edition(): void
    {
        [$account, $edition, $category] = $this->festival();
        $match = $this->readyMatch($edition, $category);
        $otherSeries = FestivalSeries::factory()->for($account)->create();
        $otherEdition = FestivalEdition::factory()->published()->for($otherSeries)->create(['account_id' => $account->id]);
        $otherCategory = FestivalCategory::factory()->for($otherEdition)->create([
            'account_id' => $account->id,
            'competition_format' => 'knockout',
        ]);
        [$judge, $assignment] = $this->judge($account, $otherEdition, $otherCategory);

        $this->expectException(HttpException::class);
        app(RecordFestivalBattleJudgeVote::class)->execute($match, $assignment, $match->entry_a_id, $judge);
    }

    /** @return array{Account, FestivalEdition, FestivalCategory} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'competition_format' => 'knockout',
            'minimum_entries_to_run' => 5,
        ]);

        return [$account, $edition, $category];
    }

    private function entry(FestivalCategory $category, bool $paid, string $status): FestivalEntry
    {
        $portalUser = FestivalPortalUser::factory()->create(['account_id' => $category->account_id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $category->account_id,
            'festival_edition_id' => $category->festival_edition_id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => $status,
        ]);
        $entry->charges()->create([
            'account_id' => $category->account_id,
            'code' => 'FC-'.Str::upper(Str::random(12)),
            'kind' => 'participation',
            'name' => 'Battle participation',
            'status' => $paid ? 'paid' : 'pending',
            'amount_cents' => 180000,
            'currency' => 'UAH',
            'paid_at' => $paid ? now() : null,
        ]);

        return $entry;
    }

    private function readyMatch(FestivalEdition $edition, FestivalCategory $category): FestivalBattleMatch
    {
        $entryA = $this->entry($category, true, 'accepted');
        $entryB = $this->entry($category, true, 'accepted');

        return FestivalBattleMatch::query()->create([
            'account_id' => $category->account_id,
            'festival_edition_id' => $edition->id,
            'festival_category_id' => $category->id,
            'round' => 1,
            'position' => 1,
            'entry_a_id' => $entryA->id,
            'entry_b_id' => $entryB->id,
            'status' => FestivalBattleMatchStatus::Ready,
        ]);
    }

    /** @return array{User, FestivalJudgeAssignment} */
    private function judge(Account $account, FestivalEdition $edition, FestivalCategory $category): array
    {
        $judge = User::factory()->create();
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);

        return [$judge, $assignment];
    }
}
