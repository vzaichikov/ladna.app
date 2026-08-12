<?php

namespace Tests\Feature;

use App\Actions\Festivals\BuildFestivalResultPreview;
use App\Actions\Festivals\PublishFestivalResults;
use App\Actions\Festivals\SaveFestivalScoreSheet;
use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPenalty;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricSection;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalSpecialistJudgingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_specialists_score_only_assigned_sections_and_results_combine_awards_and_deductions(): void
    {
        Queue::fake();
        [$account, $edition, $category, $owner] = $this->festival();
        [$rubric, $technique, $artistry, $deductions] = $this->rubric($account, $edition, $category);
        [$technicalJudge, $technicalAssignment] = $this->judge($account, $edition, $category, [$technique]);
        [$artisticJudge, $artisticAssignment] = $this->judge($account, $edition, $category, [$artistry, $deductions]);
        $entry = $this->entry($account, $edition, $category, 'Specialist performance');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.judging.score-sheets.prepare', [$account, $edition]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $technicalSheet = FestivalScoreSheet::query()->where('festival_entry_id', $entry->id)->where('festival_judge_assignment_id', $technicalAssignment->id)->firstOrFail();
        $artisticSheet = FestivalScoreSheet::query()->where('festival_entry_id', $entry->id)->where('festival_judge_assignment_id', $artisticAssignment->id)->firstOrFail();
        $techniqueCriterion = $technique->criteria()->firstOrFail();
        $artistryCriterion = $artistry->criteria()->firstOrFail();
        $deductionCriterion = $deductions->criteria()->firstOrFail();

        $this->actingAs($technicalJudge)
            ->get(route('dashboard.accounts.festivals.judging.score-sheets.edit', [$account, $edition, $technicalSheet]))
            ->assertOk()
            ->assertSee($techniqueCriterion->name)
            ->assertDontSee($artistryCriterion->name)
            ->assertDontSee($deductionCriterion->name);

        app(SaveFestivalScoreSheet::class)->execute($technicalSheet, $technicalAssignment, [
            'scores' => [['criterion_id' => $techniqueCriterion->id, 'score' => '8.00']],
            'submit' => true,
        ], $technicalJudge);
        app(SaveFestivalScoreSheet::class)->execute($artisticSheet, $artisticAssignment, [
            'scores' => [
                ['criterion_id' => $artistryCriterion->id, 'score' => '6.00'],
                ['criterion_id' => $deductionCriterion->id, 'score' => '2.00'],
            ],
            'submit' => true,
        ], $artisticJudge);
        FestivalPenalty::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'points' => '1.00',
            'reason' => 'Operational penalty',
            'created_by' => $owner->id,
        ]);

        $preview = app(BuildFestivalResultPreview::class)->execute($edition, $category);
        $this->assertSame('11.0000', $preview['rows']->sole()['total']);
        $this->assertSame('14.0000', $preview['rows']->sole()['award_total']);
        $this->assertSame('2.0000', $preview['rows']->sole()['deduction_total']);
        $this->assertSame('1.0000', $preview['rows']->sole()['ad_hoc_penalties']);

        app(PublishFestivalResults::class)->execute($edition, $category, $owner);
        $result = $entry->result()->firstOrFail();
        $this->assertSame('11.0000', $result->total_score);
        $this->assertSame('14.0000', $result->publication_details['award_total']);
        $this->assertSame('2.0000', $result->publication_details['rubric_deductions']);
        $this->assertSame('1.0000', $result->publication_details['ad_hoc_penalties']);
        $this->assertNull($result->publication_details['tie_break']);
        $this->assertSame('8.0000', $technicalSheet->refresh()->total_score);
        $this->assertSame('4.0000', $artisticSheet->refresh()->total_score);
        $this->assertSame($rubric->id, $technicalSheet->festival_rubric_id);
    }

    public function test_preparation_and_publication_reject_uncovered_sections(): void
    {
        [$account, $edition, $category, $owner] = $this->festival();
        [, $technique, $artistry] = $this->rubric($account, $edition, $category);
        $this->judge($account, $edition, $category, [$technique]);
        $this->judge($account, $edition, $category, [$artistry]);
        $this->entry($account, $edition, $category, 'Uncovered deductions');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.judging.score-sheets.prepare', [$account, $edition]))
            ->assertRedirect()
            ->assertSessionHasErrors('category');
        $this->assertDatabaseCount('festival_score_sheets', 0);

        try {
            app(BuildFestivalResultPreview::class)->execute($edition, $category);
            $this->fail('An uncovered rubric was accepted for result preview.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category', $exception->errors());
        }
    }

    public function test_judging_preparation_and_preview_enforce_the_category_minimum(): void
    {
        [$account, $edition, $category, $owner] = $this->festival();
        $category->update(['minimum_entries_to_run' => 5]);
        [, $technique, $artistry] = $this->rubric($account, $edition, $category, false);
        $this->judge($account, $edition, $category, [$technique, $artistry]);
        foreach (range(1, 4) as $number) {
            $this->entry($account, $edition, $category, 'Entry '.$number);
        }

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.judging.score-sheets.prepare', [$account, $edition]))
            ->assertSessionHasErrors('category');
        $this->assertDatabaseCount('festival_score_sheets', 0);

        try {
            app(BuildFestivalResultPreview::class)->execute($edition, $category);
            $this->fail('Result preview ignored the category minimum.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category', $exception->errors());
        }
    }

    public function test_multiple_judges_on_one_criterion_are_averaged_before_weighting(): void
    {
        [$account, $edition, $category, $owner] = $this->festival();
        $rubric = FestivalRubric::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
        ]);
        $section = $rubric->sections()->create([
            'account_id' => $account->id,
            'name' => 'Technique',
            'contribution' => 'award',
            'weight' => '1.0000',
        ]);
        $criterion = $section->criteria()->create([
            'account_id' => $account->id,
            'name' => 'Execution',
            'max_score' => '10.00',
            'weight' => '1.0000',
        ]);
        [$firstJudge, $firstAssignment] = $this->judge($account, $edition, $category, [$section]);
        [$secondJudge, $secondAssignment] = $this->judge($account, $edition, $category, [$section]);
        $entry = $this->entry($account, $edition, $category, 'Averaged performance');
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.judging.score-sheets.prepare', [$account, $edition]));

        foreach ([[$firstJudge, $firstAssignment, '8.01'], [$secondJudge, $secondAssignment, '8.02']] as [$judge, $assignment, $score]) {
            $sheet = FestivalScoreSheet::query()->where('festival_entry_id', $entry->id)->where('festival_judge_assignment_id', $assignment->id)->firstOrFail();
            app(SaveFestivalScoreSheet::class)->execute($sheet, $assignment, [
                'scores' => [['criterion_id' => $criterion->id, 'score' => $score]],
                'submit' => true,
            ], $judge);
        }

        $row = app(BuildFestivalResultPreview::class)->execute($edition, $category)['rows']->sole();
        $this->assertSame('8.0150', $row['award_total']);
        $this->assertSame('8.0150', $row['total']);
    }

    public function test_exact_ties_require_a_reasoned_jury_order_without_changing_scores(): void
    {
        Queue::fake();
        [$account, $edition, $category, $owner] = $this->festival();
        [, $technique, $artistry] = $this->rubric($account, $edition, $category, false);
        [$judge, $assignment] = $this->judge($account, $edition, $category, [$technique, $artistry]);
        $firstEntry = $this->entry($account, $edition, $category, 'First tied performance');
        $secondEntry = $this->entry($account, $edition, $category, 'Second tied performance');
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.judging.score-sheets.prepare', [$account, $edition]));
        $criterion = $technique->criteria()->firstOrFail();
        $artistryCriterion = $artistry->criteria()->firstOrFail();

        foreach ([$firstEntry, $secondEntry] as $entry) {
            $sheet = FestivalScoreSheet::query()->where('festival_entry_id', $entry->id)->where('festival_judge_assignment_id', $assignment->id)->firstOrFail();
            app(SaveFestivalScoreSheet::class)->execute($sheet, $assignment, [
                'scores' => [
                    ['criterion_id' => $criterion->id, 'score' => '8.00'],
                    ['criterion_id' => $artistryCriterion->id, 'score' => '0.00'],
                ],
                'submit' => true,
            ], $judge);
        }

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.results.preview', [$account, $edition, $category]))
            ->assertOk()
            ->assertSee('First tied performance')
            ->assertSee('Second tied performance');
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.judging.results.publish', [$account, $edition, $category]))
            ->assertSessionHasErrors('tie_breaks');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.judging.results.publish', [$account, $edition, $category]), [
                'tie_breaks' => [[
                    'total' => '8.0000',
                    'orders' => [$firstEntry->id => 2, $secondEntry->id => 1],
                    'reason' => 'The jury preferred the second performance overall.',
                ]],
            ])
            ->assertRedirect(route('dashboard.accounts.festivals.judging.results.index', [$account, $edition]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $firstEntry->result()->firstOrFail()->rank);
        $secondResult = $secondEntry->result()->firstOrFail();
        $this->assertSame(1, $secondResult->rank);
        $this->assertSame('8.0000', $secondResult->total_score);
        $this->assertSame(
            [$secondEntry->id, $firstEntry->id],
            $secondResult->publication_details['tie_break']['ordered_entry_ids'],
        );
        $this->assertSame('The jury preferred the second performance overall.', $secondResult->publication_details['tie_break']['reason']);
        $this->assertDatabaseHas('festival_criterion_scores', ['festival_rubric_criterion_id' => $criterion->id, 'score' => 8]);
        $this->assertDatabaseHas('festival_activity_logs', ['subject_id' => $category->id, 'action' => 'results.published']);
    }

    public function test_rubric_updates_preserve_ids_and_block_deleting_assigned_sections(): void
    {
        [$account, $edition, $category, $owner] = $this->festival();
        [$rubric, $technique, $artistry, $deductions] = $this->rubric($account, $edition, $category);
        $techniqueCriterion = $technique->criteria()->firstOrFail();
        [, $assignment] = $this->judge($account, $edition, $category, [$technique]);

        $payload = $this->rubricPayload($rubric, [$technique, $artistry, $deductions]);
        $payload['sections'][0]['name'] = 'Updated technique';
        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.judging.criteria.update', [$account, $edition, $rubric]), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('festival_rubric_sections', ['id' => $technique->id, 'name' => 'Updated technique']);
        $this->assertDatabaseHas('festival_rubric_criteria', ['id' => $techniqueCriterion->id]);
        $this->assertTrue($assignment->rubricSections()->whereKey($technique->id)->exists());

        $payload['sections'] = array_values(array_filter(
            $payload['sections'],
            fn (array $section): bool => (int) $section['id'] !== $technique->id,
        ));
        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.judging.criteria.update', [$account, $edition, $rubric]), $payload)
            ->assertSessionHasErrors('sections');
        $this->assertDatabaseHas('festival_rubric_sections', ['id' => $technique->id]);
    }

    /** @return array{Account, FestivalEdition, FestivalCategory, User} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        return [$account, $edition, $category, $owner];
    }

    /** @return array{FestivalRubric, FestivalRubricSection, FestivalRubricSection, FestivalRubricSection|null} */
    private function rubric(Account $account, FestivalEdition $edition, FestivalCategory $category, bool $withDeductions = true): array
    {
        $rubric = FestivalRubric::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
        ]);
        $technique = $rubric->sections()->create(['account_id' => $account->id, 'name' => 'Technique', 'contribution' => 'award']);
        $technique->criteria()->create(['account_id' => $account->id, 'name' => 'Technical execution', 'max_score' => 10]);
        $artistry = $rubric->sections()->create(['account_id' => $account->id, 'name' => 'Artistry', 'contribution' => 'award', 'sort_order' => 1]);
        $artistry->criteria()->create(['account_id' => $account->id, 'name' => 'Artistic expression', 'max_score' => 10]);
        $deductions = null;

        if ($withDeductions) {
            $deductions = $rubric->sections()->create(['account_id' => $account->id, 'name' => 'Deductions', 'contribution' => 'deduction', 'sort_order' => 2]);
            $deductions->criteria()->create(['account_id' => $account->id, 'name' => 'Protocol penalty', 'max_score' => 5]);
        }

        return [$rubric, $technique, $artistry, $deductions];
    }

    /** @param array<int, FestivalRubricSection> $sections */
    private function judge(Account $account, FestivalEdition $edition, FestivalCategory $category, array $sections): array
    {
        $judge = User::factory()->create();
        $account->users()->attach($judge->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::JudgeFestivals->value],
        ]);
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $assignment->rubricSections()->attach(
            collect($sections)->mapWithKeys(fn (FestivalRubricSection $section): array => [$section->id => ['account_id' => $account->id]])->all(),
        );

        return [$judge, $assignment];
    }

    private function entry(Account $account, FestivalEdition $edition, FestivalCategory $category, string $name): FestivalEntry
    {
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        return FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => $name,
            'status' => 'accepted',
        ]);
    }

    /** @param array<int, FestivalRubricSection> $sections */
    private function rubricPayload(FestivalRubric $rubric, array $sections): array
    {
        return [
            'festival_category_id' => $rubric->festival_category_id,
            'name' => $rubric->name,
            'is_active' => 1,
            'sections' => collect($sections)->map(fn (FestivalRubricSection $section): array => [
                'id' => $section->id,
                'name' => $section->name,
                'weight' => $section->weight,
                'contribution' => $section->contribution->value,
                'criteria' => $section->criteria()->get()->map(fn ($criterion): array => [
                    'id' => $criterion->id,
                    'name' => $criterion->name,
                    'max_score' => $criterion->max_score,
                    'weight' => $criterion->weight,
                ])->all(),
            ])->all(),
        ];
    }
}
