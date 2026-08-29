<?php

namespace Tests\Feature;

use App\Actions\Festivals\BuildFestivalResults;
use App\Actions\Festivals\SaveFestivalScoreSheet;
use App\Enums\AccountRole;
use App\Enums\FestivalScoreSheetStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FestivalJudgingManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_judging_landing_and_separated_pages_follow_manager_and_judge_permissions(): void
    {
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.index', [$account, $edition]))
            ->assertRedirect(route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition]));

        foreach ([
            'dashboard.accounts.festivals.judging.judges.index',
            'dashboard.accounts.festivals.judging.judges.create',
            'dashboard.accounts.festivals.judging.criteria.index',
            'dashboard.accounts.festivals.judging.criteria.create',
            'dashboard.accounts.festivals.judging.score-sheets.index',
            'dashboard.accounts.festivals.judging.results.index',
        ] as $routeName) {
            $this->actingAs($owner)->get(route($routeName, [$account, $edition]))->assertOk();
        }

        $judge = $this->staffJudge($account);
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $judgeRubric = FestivalRubric::factory()->for($edition)->create(['account_id' => $account->id]);

        $this->actingAs($judge)
            ->get(route('dashboard.accounts.festivals.judging.index', [$account, $edition]))
            ->assertRedirect(route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition]));
        $this->actingAs($judge)->get(route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition]))->assertOk();

        foreach ([
            'dashboard.accounts.festivals.judging.judges.index',
            'dashboard.accounts.festivals.judging.criteria.index',
            'dashboard.accounts.festivals.judging.results.index',
        ] as $routeName) {
            $this->actingAs($judge)->get(route($routeName, [$account, $edition]))->assertForbidden();
        }
        $this->actingAs($judge)
            ->delete(route('dashboard.accounts.festivals.judging.criteria.destroy', [$account, $edition, $judgeRubric]))
            ->assertForbidden();

        $assignment->update(['is_active' => false]);
        $this->actingAs($judge)->get(route('dashboard.accounts.festivals.judging.index', [$account, $edition]))->assertForbidden();
        $this->actingAs($judge)->get(route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition]))->assertForbidden();
    }

    public function test_judges_criteria_and_results_indexes_filter_paginate_and_render_dedicated_forms(): void
    {
        [$account, $edition, $category] = $this->festival();
        $secondCategory = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Silks']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $identity = User::factory()->create();

        foreach (range(1, 22) as $number) {
            $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($identity)->create([
                'account_id' => $account->id,
                'display_name' => $number === 22 ? 'Needle Judge' : 'Judge '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                'is_active' => $number !== 21,
            ]);
            $assignment->categories()->attach(($number === 22 ? $secondCategory : $category)->id, ['account_id' => $account->id]);
        }

        $judgePage = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.judges.index', [
            $account,
            $edition,
            'q' => 'Needle',
            'status' => 'active',
            'category_id' => $secondCategory->id,
        ]));
        $judgePage->assertOk()->assertSee('Needle Judge')->assertDontSee('Judge 01');
        $this->assertSame(1, $judgePage->viewData('assignments')->total());

        $paginatedJudges = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition, 'status' => 'active']));
        $this->assertSame(20, $paginatedJudges->viewData('assignments')->count());
        $paginatedJudges->assertSee('status=active', false);

        foreach (range(1, 22) as $number) {
            FestivalRubric::factory()->for($edition)->create([
                'account_id' => $account->id,
                'festival_category_id' => $number === 22 ? $secondCategory->id : $category->id,
                'name' => $number === 22 ? 'Needle Criteria' : 'Criteria '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                'is_active' => $number !== 21,
                'sort_order' => $number * 10,
            ]);
        }

        $criteriaPage = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.criteria.index', [
            $account,
            $edition,
            'q' => 'Needle',
            'status' => 'active',
            'category_id' => $secondCategory->id,
        ]));
        $criteriaPage->assertOk()->assertSee('Needle Criteria')->assertDontSee('Criteria 01');
        $this->assertSame(1, $criteriaPage->viewData('rubrics')->total());
        $this->assertSame(20, $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]))->viewData('rubrics')->count());

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.judges.create', [$account, $edition]))
            ->assertOk()
            ->assertSee('name="display_name"', false)
            ->assertSee('name="category_ids[]"', false);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.criteria.create', [$account, $edition]))
            ->assertOk()
            ->assertSee('name="sections[0][criteria][0][name]"', false);

        $judgeCreateUrl = route('dashboard.accounts.festivals.judging.judges.create', [$account, $edition]);
        $this->actingAs($owner)->from($judgeCreateUrl)->post(route('dashboard.accounts.festivals.judging.judges.store', [$account, $edition]), [
            'display_name' => 'Malformed categories',
            'category_ids' => 'not-an-array',
        ])->assertRedirect($judgeCreateUrl)->assertSessionHasErrors(['user_id', 'category_ids']);
        $this->actingAs($owner)->get($judgeCreateUrl)->assertOk();

        $portalJudge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $this->actingAs($owner)->from($judgeCreateUrl)->post(route('dashboard.accounts.festivals.judging.judges.store', [$account, $edition]), [
            'user_id' => 0,
            'festival_portal_user_id' => $portalJudge->id,
            'display_name' => 'Invalid zero identity',
            'category_ids' => [$category->id],
        ])->assertRedirect($judgeCreateUrl)->assertSessionHasErrors('user_id');
        $this->assertDatabaseMissing('festival_judge_assignments', ['festival_portal_user_id' => $portalJudge->id]);

        $criteriaCreateUrl = route('dashboard.accounts.festivals.judging.criteria.create', [$account, $edition]);
        $this->actingAs($owner)->from($criteriaCreateUrl)->post(route('dashboard.accounts.festivals.judging.criteria.store', [$account, $edition]), [
            'name' => 'Malformed structure',
            'sections' => ['not-an-array'],
        ])->assertRedirect($criteriaCreateUrl)->assertSessionHasErrors('sections.0.name');
        $this->actingAs($owner)->get($criteriaCreateUrl)->assertOk();

        $this->actingAs($owner)->from($criteriaCreateUrl)->post(route('dashboard.accounts.festivals.judging.criteria.store', [$account, $edition]), [
            'name' => 'Associative structure',
            'sections' => [
                'section-key' => [
                    'name' => 'Technique',
                    'weight' => 1,
                    'criteria' => [
                        'criterion-key' => ['name' => 'Execution', 'max_score' => 10, 'weight' => 1],
                    ],
                ],
            ],
        ])->assertRedirect($criteriaCreateUrl)->assertSessionHasErrors(['sections', 'sections.section-key.criteria']);

        foreach (range(1, 20) as $number) {
            FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Result category '.$number]);
        }

        $results = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.results.index', [$account, $edition, 'q' => 'Silks', 'publication' => 'unpublished']));
        $results->assertOk()->assertSee('Silks')->assertDontSee($category->name);
        $this->assertSame(1, $results->viewData('categories')->total());
        $this->assertSame(20, $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.results.index', [$account, $edition]))->viewData('categories')->count());
    }

    public function test_judge_and_rubric_lifecycle_actions_redirect_to_owning_pages_and_preserve_identity_history(): void
    {
        Queue::fake();
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $judge = $this->staffJudge($account);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.judging.judges.store', [$account, $edition]), [
            'user_id' => $judge->id,
            'display_name' => 'Lifecycle Judge',
            'category_ids' => [$category->id],
            'is_head_judge' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition]))->assertSessionHasNoErrors();

        $assignment = FestivalJudgeAssignment::query()->where('festival_edition_id', $edition->id)->where('user_id', $judge->id)->firstOrFail();
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.judging.judges.store', [$account, $edition]), [
            'user_id' => $judge->id,
            'display_name' => 'Duplicate Judge',
            'category_ids' => [$category->id],
            'is_active' => 1,
        ])->assertSessionHasErrors('user_id');
        $this->assertSame(1, FestivalJudgeAssignment::query()->where('festival_edition_id', $edition->id)->where('user_id', $judge->id)->count());

        $replacement = $this->staffJudge($account);
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.judging.judges.update', [$account, $edition, $assignment]), [
            'user_id' => $replacement->id,
            'display_name' => 'Forged identity change',
            'category_ids' => [$category->id],
            'is_active' => 1,
        ])->assertSessionHasErrors('user_id');
        $this->assertSame($judge->id, $assignment->refresh()->user_id);

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.judging.judges.update', [$account, $edition, $assignment]), [
            'display_name' => 'Updated Judge Name',
            'category_ids' => [$category->id],
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition]))->assertSessionHasNoErrors();
        $this->assertSame($judge->id, $assignment->refresh()->user_id);

        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.judging.judges.toggle', [$account, $edition, $assignment]))->assertRedirect();
        $this->assertFalse($assignment->refresh()->is_active);
        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.judging.judges.toggle', [$account, $edition, $assignment]))->assertRedirect();
        $this->assertTrue($assignment->refresh()->is_active);

        $rubricPayload = [
            'festival_category_id' => $category->id,
            'name' => 'Lifecycle Criteria',
            'sections' => [[
                'name' => 'Technique',
                'weight' => 1,
                'criteria' => [['name' => 'Execution', 'max_score' => 10, 'weight' => 1]],
            ]],
            'is_active' => 1,
        ];
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.judging.criteria.store', [$account, $edition]), $rubricPayload)
            ->assertRedirect(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]))
            ->assertSessionHasNoErrors();
        $rubric = FestivalRubric::query()->where('festival_edition_id', $edition->id)->where('name', 'Lifecycle Criteria')->firstOrFail();

        $secondRubricPayload = $rubricPayload;
        $secondRubricPayload['name'] = 'Inactive Criteria';
        $secondRubricPayload['is_active'] = 0;
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.judging.criteria.store', [$account, $edition]), $secondRubricPayload)->assertSessionHasNoErrors();
        $secondRubric = FestivalRubric::query()->where('festival_edition_id', $edition->id)->where('name', 'Inactive Criteria')->firstOrFail();
        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.judging.criteria.move', [$account, $edition, $secondRubric]), ['direction' => 'up'])->assertRedirect();
        $this->assertLessThan($rubric->refresh()->sort_order, $secondRubric->refresh()->sort_order);
        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.judging.criteria.toggle', [$account, $edition, $secondRubric]))->assertRedirect();
        $this->assertTrue($secondRubric->refresh()->is_active);
        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.judging.criteria.toggle', [$account, $edition, $secondRubric]))->assertRedirect();
        $this->assertFalse($secondRubric->refresh()->is_active);

        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Lifecycle Performance',
            'status' => 'accepted',
        ]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.judging.score-sheets.prepare', [$account, $edition]))
            ->assertRedirect(route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition]));
        $sheet = FestivalScoreSheet::query()->where('festival_entry_id', $entry->id)->where('festival_judge_assignment_id', $assignment->id)->firstOrFail();
        $criterion = $rubric->sections()->firstOrFail()->criteria()->firstOrFail();
        app(SaveFestivalScoreSheet::class)->execute($sheet, $assignment, [
            'scores' => [['criterion_id' => $criterion->id, 'score' => 9]],
            'submit' => true,
        ], $judge);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.results.show', [$account, $edition, $category]))
            ->assertOk()
            ->assertSee('Lifecycle Performance')
            ->assertSee('data-result-total>9</p>', false);
        $this->assertDatabaseMissing('festival_results', ['festival_entry_id' => $entry->id]);

        $updatedRubricPayload = $rubricPayload;
        $updatedRubricPayload['name'] = 'Updated Lifecycle Criteria';
        $updatedRubricPayload['sections'][0]['criteria'][0] = ['name' => 'Composition', 'max_score' => 12, 'weight' => 1];
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.judging.criteria.update', [$account, $edition, $rubric]), $updatedRubricPayload)
            ->assertRedirect(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]))
            ->assertSessionHasNoErrors();
        $this->assertSame(FestivalScoreSheetStatus::Draft, $sheet->refresh()->status);
        $this->assertDatabaseMissing('festival_results', ['festival_entry_id' => $entry->id]);
        $this->assertDatabaseMissing('festival_rubric_criteria', ['id' => $criterion->id]);
    }

    public function test_rubric_editor_saves_multiple_sections_and_fully_deletes_an_unlinked_rubric_with_confirmation(): void
    {
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.criteria.create', [$account, $edition]))
            ->assertOk()
            ->assertSee('data-festival-rubric-editor', false)
            ->assertSee('data-add-rubric-section', false)
            ->assertSee('data-remove-rubric-section', false)
            ->assertSee('data-add-rubric-criterion', false)
            ->assertSee('data-remove-rubric-criterion', false);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.judging.criteria.store', [$account, $edition]), [
            'festival_category_id' => $category->id,
            'name' => 'Complete judging rubric',
            'sections' => [
                [
                    'name' => 'Technique',
                    'weight' => 1,
                    'contribution' => 'award',
                    'criteria' => [
                        ['name' => 'Execution', 'max_score' => 10, 'weight' => 1],
                        ['name' => 'Difficulty', 'max_score' => 5, 'weight' => 2],
                    ],
                ],
                [
                    'name' => 'Penalties',
                    'weight' => 1,
                    'contribution' => 'deduction',
                    'criteria' => [
                        ['name' => 'Protocol violation', 'max_score' => 3, 'weight' => 1],
                    ],
                ],
            ],
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]))
            ->assertSessionHasNoErrors();

        $rubric = FestivalRubric::query()
            ->where('festival_edition_id', $edition->id)
            ->where('name', 'Complete judging rubric')
            ->with('sections.criteria')
            ->firstOrFail();
        $this->assertSame([0, 1], $rubric->sections->pluck('sort_order')->all());
        $this->assertSame([0, 1], $rubric->sections->first()->criteria->pluck('sort_order')->all());
        $this->assertSame(['Technique', 'Penalties'], $rubric->sections->pluck('name')->all());
        $this->assertSame(['Execution', 'Difficulty'], $rubric->sections->first()->criteria->pluck('name')->all());

        $destroyRoute = route('dashboard.accounts.festivals.judging.criteria.destroy', [$account, $edition, $rubric]);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]))
            ->assertOk()
            ->assertSee('action="'.$destroyRoute.'"', false)
            ->assertSee('data-confirm-delete', false)
            ->assertSee(__('app.festival_delete_rubric_confirm_title'));

        $sectionIds = $rubric->sections->modelKeys();
        $criterionIds = $rubric->sections->flatMap(fn ($section) => $section->criteria)->pluck('id')->all();

        $this->actingAs($owner)->delete($destroyRoute)
            ->assertRedirect(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]))
            ->assertSessionHas('status', __('app.festival_rubric_deleted'));

        $this->assertModelMissing($rubric);
        $this->assertDatabaseMissing('festival_rubric_sections', ['id' => $sectionIds[0]]);
        $this->assertDatabaseMissing('festival_rubric_criteria', ['id' => $criterionIds[0]]);
    }

    public function test_rubric_deletion_is_hidden_and_rejected_for_score_sheets_or_any_judge_assignment(): void
    {
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $judge = $this->staffJudge($account);
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create([
            'account_id' => $account->id,
            'is_active' => false,
        ]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);

        $assignedRubric = FestivalRubric::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
            'name' => 'Assigned rubric',
        ]);
        $assignedSection = $assignedRubric->sections()->create(['account_id' => $account->id, 'name' => 'Technique', 'weight' => 1]);
        $assignedSection->criteria()->create(['account_id' => $account->id, 'name' => 'Execution', 'max_score' => 10, 'weight' => 1]);
        $assignment->rubricSections()->attach($assignedSection->id, ['account_id' => $account->id]);

        $scoreRubric = FestivalRubric::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
            'name' => 'Scored rubric',
        ]);
        $scoreSection = $scoreRubric->sections()->create(['account_id' => $account->id, 'name' => 'Artistry', 'weight' => 1]);
        $scoreSection->criteria()->create(['account_id' => $account->id, 'name' => 'Expression', 'max_score' => 10, 'weight' => 1]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        FestivalScoreSheet::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'festival_judge_assignment_id' => $assignment->id,
            'festival_rubric_id' => $scoreRubric->id,
        ]);

        $assignedDestroyRoute = route('dashboard.accounts.festivals.judging.criteria.destroy', [$account, $edition, $assignedRubric]);
        $scoreDestroyRoute = route('dashboard.accounts.festivals.judging.criteria.destroy', [$account, $edition, $scoreRubric]);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('action="'.$assignedDestroyRoute.'"', false)
            ->assertDontSee('action="'.$scoreDestroyRoute.'"', false);

        $this->actingAs($owner)->delete($assignedDestroyRoute)
            ->assertRedirect(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]))
            ->assertSessionHasErrors('festival_rubric');
        $this->actingAs($owner)->delete($scoreDestroyRoute)
            ->assertRedirect(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]))
            ->assertSessionHasErrors('festival_rubric');

        $this->assertModelExists($assignedRubric);
        $this->assertModelExists($assignedSection);
        $this->assertModelExists($scoreRubric);
        $this->assertModelExists($scoreSection);
    }

    public function test_score_sheet_pages_and_legacy_redirect_never_expose_another_judges_private_scores(): void
    {
        [$account, $edition, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $firstJudge = $this->staffJudge($account);
        $secondJudge = $this->staffJudge($account);
        $firstAssignment = FestivalJudgeAssignment::factory()->for($edition)->for($firstJudge)->create(['account_id' => $account->id, 'display_name' => 'First Judge']);
        $secondAssignment = FestivalJudgeAssignment::factory()->for($edition)->for($secondJudge)->create(['account_id' => $account->id, 'display_name' => 'Second Judge']);
        $firstAssignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $secondAssignment->categories()->attach($category->id, ['account_id' => $account->id]);
        [$firstSheet] = $this->sheet($account, $edition, $category, $firstAssignment, 'FIRST PRIVATE PERFORMANCE');
        [$secondSheet] = $this->sheet($account, $edition, $category, $secondAssignment, 'SECOND PRIVATE PERFORMANCE');

        foreach (range(1, 20) as $number) {
            $this->sheet($account, $edition, $category, $firstAssignment, 'FIRST BATCH PERFORMANCE '.$number);
        }

        $privateSheets = $this->actingAs($firstJudge)
            ->get(route('dashboard.accounts.festivals.judging.score-sheets.index', [
                $account,
                $edition,
                'q' => 'FIRST PRIVATE',
                'status' => 'draft',
                'category_id' => $category->id,
            ]))
            ->assertOk()
            ->assertSee('FIRST PRIVATE PERFORMANCE')
            ->assertDontSee('SECOND PRIVATE PERFORMANCE');
        $this->assertSame(1, $privateSheets->viewData('sheets')->total());

        $paginatedSheets = $this->actingAs($firstJudge)->get(route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition]));
        $this->assertSame(21, $paginatedSheets->viewData('sheets')->total());
        $this->assertSame(20, $paginatedSheets->viewData('sheets')->count());

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('FIRST PRIVATE PERFORMANCE')
            ->assertDontSee('SECOND PRIVATE PERFORMANCE');

        $this->actingAs($firstJudge)
            ->get(route('dashboard.accounts.festivals.score-sheets.edit', [$account, $edition, $firstSheet]))
            ->assertRedirect(route('dashboard.accounts.festivals.judging.score-sheets.edit', [$account, $edition, $firstSheet]));
        $this->actingAs($firstJudge)
            ->get(route('dashboard.accounts.festivals.score-sheets.edit', [$account, $edition, $secondSheet]))
            ->assertNotFound();
        $this->actingAs($firstJudge)
            ->get(route('dashboard.accounts.festivals.judging.score-sheets.edit', [$account, $edition, $secondSheet]))
            ->assertNotFound();

        $firstAssignment->update(['is_active' => false]);
        $this->actingAs($firstJudge)->get(route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition]))->assertForbidden();
    }

    public function test_cross_account_and_cross_edition_judging_resources_are_not_found(): void
    {
        [$account, $edition] = $this->festival();
        [$otherAccount, $otherEdition, $otherCategory] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $otherJudge = User::factory()->create();
        $otherAssignment = FestivalJudgeAssignment::factory()->for($otherEdition)->for($otherJudge)->create(['account_id' => $otherAccount->id]);
        $otherAssignment->categories()->attach($otherCategory->id, ['account_id' => $otherAccount->id]);
        $otherRubric = FestivalRubric::factory()->for($otherEdition)->create(['account_id' => $otherAccount->id, 'festival_category_id' => $otherCategory->id]);

        $sameAccountSeries = FestivalSeries::factory()->for($account)->create();
        $sameAccountEdition = FestivalEdition::factory()->published()->for($sameAccountSeries)->create(['account_id' => $account->id]);
        $sameAccountCategory = FestivalCategory::factory()->for($sameAccountEdition)->create(['account_id' => $account->id]);
        $sameAccountJudge = User::factory()->create();
        $sameAccountAssignment = FestivalJudgeAssignment::factory()->for($sameAccountEdition)->for($sameAccountJudge)->create(['account_id' => $account->id]);
        $sameAccountAssignment->categories()->attach($sameAccountCategory->id, ['account_id' => $account->id]);
        $sameAccountRubric = FestivalRubric::factory()->for($sameAccountEdition)->create(['account_id' => $account->id, 'festival_category_id' => $sameAccountCategory->id]);
        $sameAccountSection = $sameAccountRubric->sections()->create(['account_id' => $account->id, 'name' => 'Technique', 'weight' => 1]);
        $sameAccountSection->criteria()->create(['account_id' => $account->id, 'name' => 'Execution', 'max_score' => 10, 'weight' => 1]);
        $sameAccountPortalUser = FestivalPortalUser::factory()->for($account)->create();
        $sameAccountEntry = FestivalEntry::factory()->for($sameAccountCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $sameAccountEdition->id,
            'festival_portal_user_id' => $sameAccountPortalUser->id,
        ]);
        $sameAccountSheet = FestivalScoreSheet::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $sameAccountEntry->id,
            'festival_judge_assignment_id' => $sameAccountAssignment->id,
            'festival_rubric_id' => $sameAccountRubric->id,
        ]);

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.judges.index', [$account, $otherEdition]))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.judges.edit', [$account, $edition, $otherAssignment]))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.criteria.edit', [$account, $edition, $otherRubric]))->assertNotFound();
        $this->actingAs($owner)->delete(route('dashboard.accounts.festivals.judging.criteria.destroy', [$account, $edition, $otherRubric]))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.results.show', [$account, $edition, $otherCategory]))->assertNotFound();

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.judges.edit', [$account, $edition, $sameAccountAssignment]))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.criteria.edit', [$account, $edition, $sameAccountRubric]))->assertNotFound();
        $this->actingAs($owner)->delete(route('dashboard.accounts.festivals.judging.criteria.destroy', [$account, $edition, $sameAccountRubric]))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.results.show', [$account, $edition, $sameAccountCategory]))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.judging.score-sheets.edit', [$account, $edition, $sameAccountSheet]))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.score-sheets.edit', [$account, $edition, $sameAccountSheet]))->assertNotFound();
    }

    public function test_guest_judge_portal_routes_continue_to_list_edit_and_save_the_guests_own_sheet(): void
    {
        [$account, $edition, $category] = $this->festival();
        $portalJudge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $assignment = FestivalJudgeAssignment::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalJudge->id,
            'display_name' => 'Guest Judge',
            'is_active' => true,
        ]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        [$sheet, $criterion] = $this->sheet($account, $edition, $category, $assignment, 'GUEST PRIVATE PERFORMANCE');
        $secondCriterion = $criterion->section()->firstOrFail()->criteria()->create([
            'account_id' => $account->id,
            'name' => 'Musicality',
            'max_score' => 10,
            'weight' => 1,
            'sort_order' => 20,
        ]);
        $sheet->entry->update([
            'act_title' => 'Midnight Flight',
            'act_description' => 'A suspended duet about finding the way home.',
        ]);
        $sheet->update(['total_score' => 1.5]);
        $indexUrl = route('festival.portal.judging.index', [$account->slug, $edition]);
        $editUrl = route('festival.portal.judging.edit', [$account->slug, $sheet]);
        $this->assertStringNotContainsString($edition->slug, $indexUrl);
        $this->assertStringNotContainsString($edition->slug, $editUrl);

        $this->actingAs($portalJudge, 'festival')
            ->get($indexUrl)
            ->assertOk()
            ->assertSee('max-w-6xl', false)
            ->assertSee('GUEST PRIVATE PERFORMANCE')
            ->assertSee('data-festival-judge-list', false)
            ->assertSee('data-refresh-seconds="5"', false);
        $fragment = $this->actingAs($portalJudge, 'festival')->get($indexUrl.'?fragment=1');
        $fragment->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertSee('data-festival-judge-list', false)
            ->assertDontSee('<html', false);
        $this->actingAs($portalJudge, 'festival')
            ->get($editUrl)
            ->assertOk()
            ->assertSee('max-w-6xl', false)
            ->assertSee('GUEST PRIVATE PERFORMANCE')
            ->assertSee('Midnight Flight')
            ->assertSee('A suspended duet about finding the way home.')
            ->assertSee('data-score-total>1,5</p>', false)
            ->assertSee('data-score-save-feedback', false)
            ->assertSee('step="0.5"', false)
            ->assertSee('data-score-stepper', false)
            ->assertSee('data-score-adjust="-1"', false)
            ->assertSee('data-score-adjust="1"', false)
            ->assertSee('grid-cols-[3rem_minmax(0,1fr)_3rem]', false)
            ->assertSee('data-score-field-status', false)
            ->assertSee('data-score-autosave-control', false)
            ->assertSee('focus-within:border-emerald-300', false)
            ->assertSee(__('app.festival_score_autosave_copy'))
            ->assertSee('data-score-save-button', false)
            ->assertDontSee(__('app.save_draft'))
            ->assertDontSee(__('app.festival_submit_scores'));
        $updateUrl = route('festival.portal.judging.update', [$account->slug, $sheet]);
        $this->actingAs($portalJudge, 'festival')
            ->putJson($updateUrl, [
                'scores' => [[
                    'criterion_id' => (string) $criterion->id,
                    'comment' => 'Comment now, score later.',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('progress.ready', false)
            ->assertJsonPath('progress.missing', 2);
        $this->assertDatabaseHas('festival_criterion_scores', [
            'festival_score_sheet_id' => $sheet->id,
            'festival_rubric_criterion_id' => $criterion->id,
            'score' => null,
            'comment' => 'Comment now, score later.',
        ]);
        $this->actingAs($portalJudge, 'festival')
            ->putJson($updateUrl, ['comments' => 'The sheet itself saves independently.'])
            ->assertOk()
            ->assertJsonPath('progress.missing', 2);
        $this->assertSame('The sheet itself saves independently.', $sheet->refresh()->comments);
        $partialResults = app(BuildFestivalResults::class)->execute($edition, $category);
        $this->assertSame(2, $partialResults['missing']);
        $this->assertSame('0.0000', $partialResults['rows']->sole()['total']);
        $this->assertFalse($partialResults['ready']);
        $this->actingAs($portalJudge, 'festival')
            ->from($editUrl)
            ->put($updateUrl, [
                'comments' => 'Still incomplete, saved through the HTML fallback.',
                'scores' => [[
                    'criterion_id' => $criterion->id,
                    'score' => null,
                    'comment' => 'Comment now, score later.',
                ]],
            ])
            ->assertRedirect($editUrl)
            ->assertSessionHasNoErrors();
        $this->actingAs($portalJudge, 'festival')
            ->putJson($updateUrl, [
                'scores' => [[
                    'criterion_id' => $criterion->id,
                    'score' => 99,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scores');
        $this->actingAs($portalJudge, 'festival')
            ->putJson($updateUrl, [
                'scores' => [[
                    'criterion_id' => (string) ($secondCriterion->id + 1000000),
                    'score' => '1',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scores');
        $this->actingAs($portalJudge, 'festival')
            ->putJson($updateUrl, [
                'scores' => [[
                    'criterion_id' => $criterion->id,
                    'score' => 8,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('progress.ready', false)
            ->assertJsonPath('progress.missing', 1)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertDatabaseHas('festival_criterion_scores', [
            'festival_score_sheet_id' => $sheet->id,
            'festival_rubric_criterion_id' => $criterion->id,
            'score' => 8,
            'comment' => 'Comment now, score later.',
        ]);
        $this->assertSame('Still incomplete, saved through the HTML fallback.', $sheet->refresh()->comments);
        $this->actingAs($portalJudge, 'festival')
            ->putJson($updateUrl, [
                'scores' => [[
                    'criterion_id' => (string) $secondCriterion->id,
                    'score' => '7.5',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('progress.ready', true)
            ->assertJsonPath('progress.missing', 0)
            ->assertJsonPath('total_score', '15.5000');
        $this->assertDatabaseHas('festival_criterion_scores', [
            'festival_score_sheet_id' => $sheet->id,
            'festival_rubric_criterion_id' => $secondCriterion->id,
            'score' => 7.5,
        ]);
    }

    public function test_staff_judge_list_groups_categories_tracks_timeline_and_serves_only_owned_performance_photos(): void
    {
        Storage::fake('local');
        [$account, $edition, $category] = $this->festival();
        $category->update(['name' => 'Aerial Hoop', 'sort_order' => 10]);
        $nextCategory = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Aerial Silks',
            'sort_order' => 20,
        ]);
        $judge = $this->staffJudge($account);
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach([$category->id, $nextCategory->id], ['account_id' => $account->id]);
        [$activeSheet] = $this->sheet($account, $edition, $category, $assignment, 'CURRENT DANCE');
        [$nextSheet] = $this->sheet($account, $edition, $nextCategory, $assignment, 'NEXT DANCE');

        Storage::disk('local')->put('festival/participants/current.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $participant = FestivalParticipant::factory()->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $activeSheet->entry->festival_portal_user_id,
            'photo_path' => 'festival/participants/current.png',
            'is_profile_owner' => false,
        ]);
        $activeSheet->entry->participants()->attach($participant->id, ['account_id' => $account->id, 'sort_order' => 0]);
        $otherParticipant = FestivalParticipant::factory()->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => $nextSheet->entry->festival_portal_user_id,
            'is_profile_owner' => false,
        ]);
        $nextSheet->entry->participants()->attach($otherParticipant->id, ['account_id' => $account->id, 'sort_order' => 0]);

        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Main scene']);
        $timeline = FestivalTimeline::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'started_at' => now(),
            'next_transition_at' => now()->addMinutes(2),
        ]);
        $activeItem = FestivalTimelineItem::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_timeline_id' => $timeline->id,
            'festival_entry_id' => $activeSheet->festival_entry_id,
            'label' => 'CURRENT DANCE',
            'type' => 'performance',
            'sort_order' => 10,
        ]);
        FestivalTimelineItem::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_timeline_id' => $timeline->id,
            'festival_entry_id' => $nextSheet->festival_entry_id,
            'label' => 'NEXT DANCE',
            'type' => 'performance',
            'sort_order' => 20,
        ]);
        $timeline->update(['active_item_id' => $activeItem->id]);

        $photoUrl = route('dashboard.accounts.festivals.judging.score-sheets.participants.photo', [$account, $edition, $activeSheet, $participant]);
        $response = $this->actingAs($judge)
            ->get(route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition]))
            ->assertOk()
            ->assertSee('data-festival-judge-list', false)
            ->assertSee('data-refresh-seconds="5"', false)
            ->assertSee('Main scene')
            ->assertSee('CURRENT DANCE')
            ->assertSee('NEXT DANCE')
            ->assertSee('Aerial Hoop')
            ->assertSee('Aerial Silks')
            ->assertSee(__('app.festival_current_performance'))
            ->assertSee(__('app.festival_active_category'))
            ->assertSee(__('app.festival_timeline_status_active'))
            ->assertSee(__('app.festival_timeline_status_next'))
            ->assertSee('data-current-performance-link', false)
            ->assertSee(route('dashboard.accounts.festivals.judging.score-sheets.edit', [$account, $edition, $activeSheet]), false)
            ->assertSee($photoUrl, false);
        $this->assertCount(2, $response->viewData('judgeGroups'));

        $this->actingAs($judge)->get($photoUrl)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($judge)
            ->get(route('dashboard.accounts.festivals.judging.score-sheets.participants.photo', [$account, $edition, $activeSheet, $otherParticipant]))
            ->assertNotFound();
    }

    public function test_judging_sidebar_uses_the_exact_localized_group_and_four_destinations(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->withSession(['locale' => 'uk'])
            ->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition]))
            ->assertOk()
            ->assertSee('Судді та результати')
            ->assertSee('Судді')
            ->assertSee('Критерії')
            ->assertSee('Суддівські листи')
            ->assertSee('Результати');

        $this->withSession(['locale' => 'en'])
            ->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition]))
            ->assertOk()
            ->assertSee('Judges &amp; results', false)
            ->assertSee('Judges')
            ->assertSee('Criteria')
            ->assertSee('Score sheets')
            ->assertSee('Results');
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

    private function staffJudge(Account $account): User
    {
        $judge = User::factory()->create();
        $account->users()->attach($judge->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::JudgeFestivals->value],
        ]);

        return $judge;
    }

    /** @return array{FestivalScoreSheet, FestivalRubricCriterion} */
    private function sheet(Account $account, FestivalEdition $edition, FestivalCategory $category, FestivalJudgeAssignment $assignment, string $entryName): array
    {
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => $entryName,
            'status' => 'accepted',
        ]);
        $rubric = FestivalRubric::factory()->for($edition)->create(['account_id' => $account->id, 'festival_category_id' => $category->id]);
        $section = $rubric->sections()->create(['account_id' => $account->id, 'name' => 'Technique', 'weight' => 1]);
        $criterion = $section->criteria()->create(['account_id' => $account->id, 'name' => 'Execution', 'max_score' => 10, 'weight' => 1]);
        $sheet = FestivalScoreSheet::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'festival_judge_assignment_id' => $assignment->id,
            'festival_rubric_id' => $rubric->id,
        ]);

        return [$sheet, $criterion];
    }
}
