<?php

namespace Tests\Feature;

use App\Actions\Festivals\FinalizeFestivalBattleMatch;
use App\Enums\AccountApiTokenAbility;
use App\Enums\AccountMode;
use App\Enums\FestivalBattleMatchStatus;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\SubscriptionPlanType;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountApiToken;
use App\Models\FestivalActivityLog;
use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalSeries;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AccountApiTokenIssuer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class FestivalBattleApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ready_match_picker_is_token_scoped_and_returns_only_safe_fields(): void
    {
        [$account, $edition, $category, $match] = $this->festivalMatch();
        $token = $this->token($account);
        $draftEdition = FestivalEdition::factory()->for(FestivalSeries::factory()->for($account))->create(['account_id' => $account->id]);
        $draftCategory = FestivalCategory::factory()->for($draftEdition)->create(['account_id' => $account->id, 'competition_format' => 'knockout']);
        $draftEntryA = $this->entry($draftCategory);
        $draftEntryB = $this->entry($draftCategory);
        FestivalBattleMatch::query()->create([
            'festival_category_id' => $draftCategory->id,
            'account_id' => $account->id,
            'festival_edition_id' => $draftEdition->id,
            'round' => 1,
            'position' => 1,
            'entry_a_id' => $draftEntryA->id,
            'entry_b_id' => $draftEntryB->id,
            'status' => FestivalBattleMatchStatus::Ready,
        ]);
        FestivalBattleMatch::query()->create([
            'festival_category_id' => $category->id,
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'round' => 1,
            'position' => 2,
            'entry_a_id' => $match->entry_a_id,
            'entry_b_id' => $match->entry_b_id,
            'status' => FestivalBattleMatchStatus::Completed,
        ]);

        $this->withToken($token->tokenValue())
            ->getJson(route('api.v1.festival-battles.matches.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.edition_label', $edition->title)
            ->assertJsonPath('data.0.category_label', $category->name)
            ->assertJsonPath('data.0.state', 'ready')
            ->assertJsonPath('meta.locale', 'en')
            ->assertJsonPath('meta.poll_interval_seconds', 5)
            ->assertJsonMissingPath('data.0.performer_a.email')
            ->assertJsonMissingPath('data.0.judge_votes.identities');
    }

    public function test_token_authentication_ability_capability_and_tenant_boundaries_fail_closed(): void
    {
        [$account, , , $match] = $this->festivalMatch();
        $wrongAbilityToken = app(AccountApiTokenIssuer::class)->issue($account, 'Wrong scope', [AccountApiTokenAbility::McpRead]);

        $this->getJson(route('api.v1.festival-battles.matches.index'))->assertUnauthorized();
        $this->withToken('ladna_invalid')->getJson(route('api.v1.festival-battles.matches.index'))->assertUnauthorized();
        $this->withToken($wrongAbilityToken->tokenValue())
            ->getJson(route('api.v1.festival-battles.matches.index'))
            ->assertForbidden();

        $revoked = $this->token($account);
        $revoked->update(['is_active' => false]);
        $this->withToken($revoked->tokenValue())
            ->getJson(route('api.v1.festival-battles.matches.index'))
            ->assertUnauthorized();

        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $otherToken = $this->token($otherAccount);
        $this->withToken($otherToken->tokenValue())
            ->getJson(route('api.v1.festival-battles.matches.show', $match))
            ->assertNotFound();

        $account->update(['enable_festivals' => false]);
        $this->withToken($this->token($account)->tokenValue())
            ->getJson(route('api.v1.festival-battles.matches.index'))
            ->assertNotFound();
    }

    public function test_expired_subscription_demo_and_reversed_purchase_locks_are_enforced(): void
    {
        [$account, $edition, , $match] = $this->festivalMatch();
        $token = $this->token($account);
        $plan = SubscriptionPlan::factory()->create(['plan_type' => SubscriptionPlanType::Standard]);
        $account->subscription()->create([
            'subscription_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'started_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);

        $this->withToken($token->tokenValue())
            ->getJson(route('api.v1.festival-battles.matches.index'))
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_expired');

        $account->subscription()->delete();
        FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'status' => FestivalEditionPurchaseStatus::PaymentReversed,
            'reversed_at' => now(),
        ]);
        $this->withToken($token->tokenValue())
            ->putJson(route('api.v1.festival-battles.matches.audience-score.update', $match), $this->scores())
            ->assertStatus(423);

        [$demoAccount, , , $demoMatch] = $this->festivalMatch(AccountMode::DemoReadonly);
        $this->withToken($this->token($demoAccount)->tokenValue())
            ->putJson(route('api.v1.festival-battles.matches.audience-score.update', $demoMatch), $this->scores())
            ->assertStatus(423)
            ->assertJsonPath('code', 'demo_readonly');
    }

    public function test_production_requests_require_tls(): void
    {
        [$account] = $this->festivalMatch();
        $token = $this->token($account);
        $originalEnvironment = app()->environment();

        app()->detectEnvironment(fn (): string => 'production');

        try {
            $this->withToken($token->tokenValue())
                ->getJson('http://ladna.local/api/v1/festival-battles/matches')
                ->assertStatus(426)
                ->assertJsonPath('code', 'https_required');

            $this->withToken($token->tokenValue())
                ->getJson('https://ladna.local/api/v1/festival-battles/matches')
                ->assertOk();
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    public function test_rate_limit_is_keyed_by_api_token(): void
    {
        [$account] = $this->festivalMatch();
        $firstToken = $this->token($account);
        $secondToken = $this->token($account);
        $rateLimitNamespace = 'festival-battle-api-test-'.Str::uuid();
        $seenTokenIds = [];
        RateLimiter::for('festival-battles-api', function (Request $request) use ($rateLimitNamespace, &$seenTokenIds): Limit {
            $apiToken = $request->attributes->get('accountApiToken');
            $seenTokenIds[] = $apiToken?->id;

            return Limit::perMinute(1)->by(
                $rateLimitNamespace.':'.($apiToken instanceof AccountApiToken ? $apiToken->id : $request->ip()),
            );
        });
        $url = route('api.v1.festival-battles.matches.index');

        $this->withToken($firstToken->tokenValue())->getJson($url)->assertOk();
        $this->withToken($firstToken->tokenValue())->getJson($url)->assertTooManyRequests();
        $secondTokenResponse = $this->withToken($secondToken->tokenValue())->getJson($url);

        $this->assertSame([$firstToken->id, $firstToken->id, $secondToken->id], $seenTokenIds);
        $secondTokenResponse->assertOk();
    }

    public function test_score_validation_requires_exact_normalized_total_and_rejects_account_scope_input(): void
    {
        [$account, , , $match] = $this->festivalMatch();

        $this->withToken($this->token($account)->tokenValue())
            ->putJson(route('api.v1.festival-battles.matches.audience-score.update', $match), [
                'account_id' => $account->id,
                'audience_score_a' => 500000,
                'audience_score_b' => 499999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'audience_score_b']);
    }

    public function test_waiting_score_is_stored_once_and_identical_retries_are_idempotent(): void
    {
        [$account, , , $match] = $this->festivalMatch();
        $token = $this->token($account);
        $url = route('api.v1.festival-battles.matches.audience-score.update', $match);

        $this->withToken($token->tokenValue())
            ->putJson($url, $this->scores())
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'waiting_for_judges')
            ->assertJsonPath('data.audience.score_a', 600000)
            ->assertJsonPath('data.audience.percentage_a', 60);
        $this->withToken($token->tokenValue())
            ->putJson($url, $this->scores())
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'waiting_for_judges');

        $this->assertSame(1, FestivalActivityLog::query()
            ->where('subject_type', $match->getMorphClass())
            ->where('subject_id', $match->id)
            ->where('action', 'battle.audience_score_recorded')
            ->count());
        $this->assertDatabaseHas('festival_activity_logs', [
            'subject_id' => $match->id,
            'actor_account_api_token_id' => $token->id,
        ]);
        $activity = FestivalActivityLog::query()
            ->where('subject_id', $match->id)
            ->where('action', 'battle.audience_score_recorded')
            ->firstOrFail();
        $this->assertSame('baseline_adjusted_integrated_energy', $activity->payload['measurement']['metric']);
        $this->assertSame(5000, $activity->payload['measurement']['duration_ms']);
        $this->withToken($token->tokenValue())
            ->putJson($url, ['audience_score_a' => 550000, 'audience_score_b' => 450000])
            ->assertConflict()
            ->assertJsonPath('code', 'audience_score_conflict');

        $this->judgeVotes($match, ['a', 'a', 'a', 'b']);
        $this->withToken($token->tokenValue())
            ->putJson($url, $this->scores())
            ->assertOk()
            ->assertJsonPath('data.state', 'completed');
    }

    public function test_four_judge_votes_finalize_official_result_and_advance_bracket_transactionally(): void
    {
        [$account, $edition, $category, $match] = $this->festivalMatch();
        $token = $this->token($account);
        $nextMatch = FestivalBattleMatch::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_category_id' => $category->id,
            'round' => 2,
            'position' => 1,
            'status' => FestivalBattleMatchStatus::Pending,
        ]);
        $match->update(['next_match_id' => $nextMatch->id, 'next_position' => 'a']);
        $this->judgeVotes($match, ['a', 'a', 'a', 'b']);

        $this->withToken($token->tokenValue())
            ->putJson(route('api.v1.festival-battles.matches.audience-score.update', $match), $this->scores())
            ->assertOk()
            ->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.judge_votes.a', 3)
            ->assertJsonPath('data.audience.percentage_a', 60)
            ->assertJsonPath('data.combined.percentage_a', 67.5)
            ->assertJsonPath('data.winner.id', $match->entry_a_id);

        $match->refresh();
        $this->assertSame(FestivalBattleMatchStatus::Completed, $match->status);
        $this->assertNull($match->decided_by);
        $this->assertSame($token->id, $match->decided_by_account_api_token_id);
        $this->assertSame($match->entry_a_id, $nextMatch->refresh()->entry_a_id);
        $this->assertDatabaseHas('festival_activity_logs', [
            'subject_id' => $match->id,
            'action' => 'battle.match_finalized',
            'actor_account_api_token_id' => $token->id,
        ]);
        $this->withToken($token->tokenValue())
            ->putJson(route('api.v1.festival-battles.matches.audience-score.update', $match), $this->scores())
            ->assertOk();
        $this->withToken($token->tokenValue())
            ->putJson(route('api.v1.festival-battles.matches.audience-score.update', $match), [
                'audience_score_a' => 590000,
                'audience_score_b' => 410000,
            ])
            ->assertConflict();
    }

    public function test_exact_tie_waits_for_manager_decision_then_polling_returns_user_attributed_winner(): void
    {
        [$account, , , $match] = $this->festivalMatch();
        $token = $this->token($account);
        $this->judgeVotes($match, ['a', 'a', 'b', 'b']);
        $tieScores = ['audience_score_a' => 500000, 'audience_score_b' => 500000];

        $this->withToken($token->tokenValue())
            ->putJson(route('api.v1.festival-battles.matches.audience-score.update', $match), $tieScores)
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'jury_decision_required')
            ->assertJsonPath('data.winner', null);

        $manager = User::factory()->create();
        app(FinalizeFestivalBattleMatch::class)->execute(
            $match,
            500000,
            500000,
            $manager,
            $match->entry_b_id,
            'Jury selected the stronger complete performance.',
        );
        $this->withToken($token->tokenValue())
            ->getJson(route('api.v1.festival-battles.matches.show', $match))
            ->assertOk()
            ->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.winner.id', $match->entry_b_id);
        $this->assertSame($manager->id, $match->refresh()->decided_by);
        $this->assertNull($match->decided_by_account_api_token_id);
    }

    public function test_existing_battle_form_prefills_pending_applause_scores(): void
    {
        [$account, $edition, , $match] = $this->festivalMatch();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $match->update(['audience_votes_a' => 612345, 'audience_votes_b' => 387655]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.battles.index', [$account, $edition]))
            ->assertOk()
            ->assertSee('value="612345"', false)
            ->assertSee('value="387655"', false);
    }

    /** @return array{Account, FestivalEdition, FestivalCategory, FestivalBattleMatch} */
    private function festivalMatch(AccountMode $mode = AccountMode::Live): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en', 'mode' => $mode]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'competition_format' => 'knockout']);
        $entryA = FestivalEntry::factory()->create([
            'festival_category_id' => $category->id,
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'entry_name' => 'Performer Alpha',
        ]);
        $entryB = FestivalEntry::factory()->create([
            'festival_category_id' => $category->id,
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'entry_name' => 'Performer Beta',
        ]);
        $match = FestivalBattleMatch::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_category_id' => $category->id,
            'round' => 1,
            'position' => 1,
            'entry_a_id' => $entryA->id,
            'entry_b_id' => $entryB->id,
            'status' => FestivalBattleMatchStatus::Ready,
        ]);

        return [$account, $edition, $category, $match];
    }

    private function token(Account $account): AccountApiToken
    {
        return app(AccountApiTokenIssuer::class)->issue($account, 'Battle operator', [
            AccountApiTokenAbility::FestivalBattlesOperate,
        ]);
    }

    private function entry(FestivalCategory $category): FestivalEntry
    {
        return FestivalEntry::factory()->create([
            'festival_category_id' => $category->id,
            'account_id' => $category->account_id,
            'festival_edition_id' => $category->festival_edition_id,
        ]);
    }

    /** @param array<int, 'a'|'b'> $selections */
    private function judgeVotes(FestivalBattleMatch $match, array $selections): void
    {
        foreach ($selections as $selection) {
            $assignment = FestivalJudgeAssignment::factory()->for($match->edition)->create(['account_id' => $match->account_id]);
            $assignment->categories()->attach($match->festival_category_id, ['account_id' => $match->account_id]);
            FestivalBattleJudgeVote::factory()->for($match, 'match')->for($assignment, 'assignment')->create([
                'account_id' => $match->account_id,
                'festival_edition_id' => $match->festival_edition_id,
                'festival_category_id' => $match->festival_category_id,
                'selected_entry_id' => $selection === 'a' ? $match->entry_a_id : $match->entry_b_id,
            ]);
        }
    }

    /** @return array<string, int|float|string> */
    private function scores(): array
    {
        return [
            'audience_score_a' => 600000,
            'audience_score_b' => 400000,
            'measurement' => [
                'metric' => 'baseline_adjusted_integrated_energy',
                'baseline_duration_ms' => 2000,
                'duration_ms' => 5000,
                'baseline_dbfs' => -52.4,
                'mean_dbfs_a' => -18.2,
                'mean_dbfs_b' => -20.1,
                'peak_dbfs_a' => -2.1,
                'peak_dbfs_b' => -3.4,
            ],
        ];
    }
}
