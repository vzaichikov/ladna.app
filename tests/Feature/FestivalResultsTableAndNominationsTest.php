<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalNomination;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\User;
use App\Support\Festivals\FestivalTelegramMiniAppData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FestivalResultsTableAndNominationsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_edit_judge_source_cells_and_derived_summary_is_returned(): void
    {
        [$account, $edition, $category, $sheet, $criterion] = $this->scoredFestival();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.results.table', [$account, $edition, $category, 'tab' => 'judge-'.$sheet->festival_judge_assignment_id]))
            ->assertOk()
            ->assertSee('data-festival-result-table', false)
            ->assertSee('data-result-table-form', false)
            ->assertSee('data-result-judge-grid', false)
            ->assertSee('data-result-criterion-header="'.$criterion->id.'"', false)
            ->assertSee('data-result-criterion-cell="'.$criterion->id.'"', false)
            ->assertSee(__('app.festival_result_table_summary'));

        $this->actingAs($owner)
            ->putJson(route('dashboard.accounts.festivals.judging.results.table.score-sheets.update', [$account, $edition, $category, $sheet]), [
                'scores' => [['criterion_id' => $criterion->id, 'score' => 8.5, 'comment' => 'Precise']],
            ])
            ->assertOk()
            ->assertJsonPath('sheet_total', '8.5000')
            ->assertJsonPath('sheet_id', $sheet->id)
            ->assertJson(fn ($json) => $json->whereType('summary_html', 'string')->etc());

        $this->assertDatabaseHas('festival_criterion_scores', [
            'festival_score_sheet_id' => $sheet->id,
            'festival_rubric_criterion_id' => $criterion->id,
            'score' => 8.5,
            'comment' => 'Precise',
        ]);
    }

    public function test_manager_can_view_but_cannot_edit_results_table(): void
    {
        [$account, $edition, $category, $sheet, $criterion] = $this->scoredFestival();
        $manager = User::factory()->create();
        $account->users()->attach($manager->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageFestivals->value],
        ]);

        $this->actingAs($manager)
            ->get(route('dashboard.accounts.festivals.judging.results.table', [$account, $edition, $category]))
            ->assertOk()
            ->assertSee(__('app.festival_result_table_read_only'));
        $this->actingAs($manager)
            ->putJson(route('dashboard.accounts.festivals.judging.results.table.score-sheets.update', [$account, $edition, $category, $sheet]), [
                'scores' => [['criterion_id' => $criterion->id, 'score' => 7]],
            ])
            ->assertForbidden();
    }

    public function test_owner_can_create_update_and_delete_multiple_penalty_rows(): void
    {
        [$account, $edition, $category, $sheet, $criterion] = $this->scoredFestival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $entry = $sheet->entry;

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.festivals.judging.results.table.penalties.store', [$account, $edition, $category]), [
                'festival_entry_id' => $entry->id,
                'points' => 1.5,
                'reason' => 'Time limit',
            ])
            ->assertOk()
            ->assertJsonPath('reload', true);
        $penalty = $entry->penalties()->sole();
        $this->actingAs($owner)
            ->putJson(route('dashboard.accounts.festivals.judging.results.table.penalties.update', [$account, $edition, $category, $penalty]), [
                'festival_entry_id' => $entry->id,
                'points' => 2,
                'reason' => 'Updated reason',
            ])
            ->assertOk();
        $this->assertDatabaseHas('festival_penalties', ['id' => $penalty->id, 'points' => 2, 'reason' => 'Updated reason']);
        $this->actingAs($owner)
            ->deleteJson(route('dashboard.accounts.festivals.judging.results.table.penalties.destroy', [$account, $edition, $category, $penalty]))
            ->assertOk();
        $this->assertDatabaseMissing('festival_penalties', ['id' => $penalty->id]);
    }

    public function test_portal_head_judge_can_use_table_while_ordinary_judge_cannot(): void
    {
        [$account, $edition, $category, $sheet, $criterion] = $this->scoredFestival();
        $headJudge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $headAssignment = FestivalJudgeAssignment::factory()->for($edition)->create([
            'account_id' => $account->id,
            'user_id' => null,
            'festival_portal_user_id' => $headJudge->id,
            'is_head_judge' => true,
        ]);
        $headAssignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $ordinaryJudge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $ordinaryAssignment = FestivalJudgeAssignment::factory()->for($edition)->create([
            'account_id' => $account->id,
            'user_id' => null,
            'festival_portal_user_id' => $ordinaryJudge->id,
            'is_head_judge' => false,
        ]);
        $ordinaryAssignment->categories()->attach($category->id, ['account_id' => $account->id]);

        $this->actingAs($headJudge, 'festival')
            ->get(route('festival.portal.judging.results.table', [$account->slug, $edition, $category]))
            ->assertOk();
        $this->actingAs($headJudge, 'festival')
            ->putJson(route('festival.portal.judging.results.table.score-sheets.update', [$account->slug, $edition, $category, $sheet]), [
                'scores' => [['criterion_id' => $criterion->id, 'score' => 9]],
            ])
            ->assertOk()
            ->assertJsonPath('sheet_total', '9.0000');
        $this->actingAs($ordinaryJudge, 'festival')
            ->get(route('festival.portal.judging.results.nominations.index', [$account->slug, $edition]))
            ->assertForbidden();
    }

    public function test_head_judge_is_category_scoped_and_sync_preserves_recipients_outside_that_scope(): void
    {
        [$account, $edition, $category] = $this->festival();
        $otherCategory = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $headJudge = User::factory()->create();
        $account->users()->attach($headJudge->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::JudgeFestivals->value],
        ]);
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($headJudge)->create(['account_id' => $account->id, 'is_head_judge' => true]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $firstParticipant = $this->acceptedParticipant($account, $edition, $category, 'Scoped');
        $otherParticipant = $this->acceptedParticipant($account, $edition, $otherCategory, 'Preserved');
        $nomination = FestivalNomination::factory()->for($edition)->create(['account_id' => $account->id]);
        $nomination->participants()->attach([$firstParticipant->id, $otherParticipant->id], ['account_id' => $account->id]);

        $this->actingAs($headJudge)
            ->get(route('dashboard.accounts.festivals.judging.results.nominations.index', [$account, $edition]))
            ->assertOk()
            ->assertSee('Scoped')
            ->assertDontSee('Preserved');
        $this->actingAs($headJudge)
            ->putJson(route('dashboard.accounts.festivals.judging.results.nominations.update', [$account, $edition, $nomination]), [
                'participant_ids_present' => '1',
                'participant_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('reload', true);

        $this->assertDatabaseMissing('festival_nomination_participant', ['festival_nomination_id' => $nomination->id, 'festival_participant_id' => $firstParticipant->id]);
        $this->assertDatabaseHas('festival_nomination_participant', ['festival_nomination_id' => $nomination->id, 'festival_participant_id' => $otherParticipant->id]);
        $this->actingAs($headJudge)
            ->get(route('dashboard.accounts.festivals.judging.results.table', [$account, $edition, $otherCategory]))
            ->assertForbidden();
    }

    public function test_assigned_nomination_cannot_be_deleted_and_blocks_participant_archiving(): void
    {
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $participant = FestivalParticipant::factory()->for($portalUser, 'portalUser')->create(['account_id' => $account->id]);
        $nomination = FestivalNomination::factory()->for($edition)->create(['account_id' => $account->id]);
        $nomination->participants()->attach($participant->id, ['account_id' => $account->id]);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.nominations.destroy', [$account, $edition, $nomination]))
            ->assertSessionHasErrors('nomination');
        $this->assertTrue($participant->refresh()->isInUse());
        $this->assertDatabaseHas('festival_nominations', ['id' => $nomination->id]);
    }

    public function test_nomination_settings_crud_persists_publication_fields(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.nominations.store', [$account, $edition]), [
                'name' => 'Partner choice',
                'description' => 'For a memorable performance.',
                'presented_by' => 'Main Partner',
                'prize' => 'Workshop voucher',
                'is_active' => '1',
                'show_in_mini_app' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.festivals.settings.nominations', [$account, $edition]))
            ->assertSessionHasNoErrors();
        $nomination = FestivalNomination::query()->where('festival_edition_id', $edition->id)->sole();
        $this->assertTrue($nomination->show_in_mini_app);
        $this->assertSame('Main Partner', $nomination->presented_by);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.nominations.update', [$account, $edition, $nomination]), [
                'name' => 'Updated choice',
                'description' => null,
                'presented_by' => 'Updated Partner',
                'prize' => null,
                'is_active' => '1',
                'show_in_mini_app' => '0',
            ])
            ->assertSessionHasNoErrors();
        $this->assertFalse($nomination->refresh()->show_in_mini_app);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.nominations.destroy', [$account, $edition, $nomination]))
            ->assertRedirect();
        $this->assertDatabaseMissing('festival_nominations', ['id' => $nomination->id]);
    }

    public function test_mini_app_exposes_only_enabled_nomination_metadata_without_recipients(): void
    {
        [$account, $edition, $category] = $this->festival();
        $participant = $this->acceptedParticipant($account, $edition, $category, 'Private Winner');
        $visible = FestivalNomination::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Audience Choice',
            'description' => 'Public description',
            'presented_by' => 'Festival Partner',
            'prize' => 'Gift certificate',
            'show_in_mini_app' => true,
        ]);
        $hidden = FestivalNomination::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Hidden Award', 'show_in_mini_app' => false]);
        $visible->participants()->attach($participant->id, ['account_id' => $account->id]);

        $editionData = collect(app(FestivalTelegramMiniAppData::class)->build($edition->series)['editions'])->firstWhere('id', $edition->id);

        $this->assertSame('Audience Choice', $editionData['nominations'][0]['name']);
        $this->assertSame('Festival Partner', $editionData['nominations'][0]['presented_by']);
        $this->assertArrayNotHasKey('participants', $editionData['nominations'][0]);
        $this->assertStringNotContainsString('Private Winner', json_encode($editionData['nominations'], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($hidden->name, json_encode($editionData['nominations'], JSON_THROW_ON_ERROR));
    }

    /** @return array{Account, FestivalEdition, FestivalCategory, FestivalScoreSheet, FestivalRubricCriterion} */
    private function scoredFestival(): array
    {
        [$account, $edition, $category] = $this->festival();
        $judge = User::factory()->create();
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $participant = $this->acceptedParticipant($account, $edition, $category, 'Table Performer');
        $entry = $participant->entries()->firstOrFail();
        $rubric = FestivalRubric::factory()->for($edition)->create(['account_id' => $account->id, 'festival_category_id' => $category->id]);
        $section = $rubric->sections()->create(['account_id' => $account->id, 'name' => 'Technique', 'weight' => 1]);
        $criterion = $section->criteria()->create(['account_id' => $account->id, 'name' => 'Execution', 'max_score' => 10, 'weight' => 1]);
        $sheet = FestivalScoreSheet::query()->create(['account_id' => $account->id, 'festival_entry_id' => $entry->id, 'festival_judge_assignment_id' => $assignment->id, 'festival_rubric_id' => $rubric->id]);

        return [$account, $edition, $category, $sheet, $criterion];
    }

    /** @return array{Account, FestivalEdition, FestivalCategory} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);

        return [$account, $edition, $category];
    }

    private function acceptedParticipant(Account $account, FestivalEdition $edition, FestivalCategory $category, string $firstName): FestivalParticipant
    {
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $participant = FestivalParticipant::factory()->for($portalUser, 'portalUser')->create(['account_id' => $account->id, 'first_name' => $firstName, 'last_name' => 'Performer']);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => $firstName.' act',
            'status' => 'accepted',
        ]);
        $entry->participants()->attach($participant->id, ['account_id' => $account->id, 'sort_order' => 10]);

        return $participant;
    }
}
