<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Actions\Festivals\PublishFestivalResults;
use App\Actions\Festivals\SaveFestivalScheduleSlot;
use App\Actions\Festivals\SaveFestivalScoreSheet;
use App\Enums\FestivalScoreSheetStatus;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalNotification;
use App\Models\FestivalPenalty;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FestivalOperationsAndJudgingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_schedule_prevents_stage_overlap_and_audits_rescheduling(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $category] = $this->festival();
        $user = User::factory()->create();
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        $firstEntry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id, 'status' => 'accepted']);
        $secondEntry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id, 'status' => 'accepted']);
        $startsAt = now($edition->timezone)->addMonth()->format('Y-m-d H:i:s');
        $endsAt = now($edition->timezone)->addMonth()->addMinutes(10)->format('Y-m-d H:i:s');
        $slot = app(SaveFestivalScheduleSlot::class)->execute($edition, ['festival_stage_id' => $stage->id, 'festival_entry_id' => $firstEntry->id, 'type' => 'performance', 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'is_published' => true], $user);

        try {
            app(SaveFestivalScheduleSlot::class)->execute($edition, ['festival_stage_id' => $stage->id, 'festival_entry_id' => $secondEntry->id, 'type' => 'performance', 'starts_at' => $startsAt, 'ends_at' => $endsAt], $user);
            $this->fail('Overlapping Festival slot was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('festival_schedule_slots', 1);
        }

        app(SaveFestivalScheduleSlot::class)->execute($edition, ['festival_stage_id' => $stage->id, 'festival_entry_id' => $firstEntry->id, 'type' => 'performance', 'starts_at' => now($edition->timezone)->addMonth()->addHour()->format('Y-m-d H:i:s'), 'ends_at' => now($edition->timezone)->addMonth()->addHour()->addMinutes(10)->format('Y-m-d H:i:s'), 'reschedule_reason' => 'Stage reset', 'is_published' => true], $user, $slot);
        $this->assertDatabaseHas('festival_activity_logs', ['subject_id' => $slot->id, 'action' => 'schedule.rescheduled']);
    }

    public function test_notification_outbox_deduplicates_immutable_payloads(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser] = $this->festival();
        $payload = ['subject' => 'Application received', 'lines' => ['We received it.']];
        app(FestivalNotificationOutbox::class)->queue($portalUser, $edition, 'entry_submitted', $payload);
        app(FestivalNotificationOutbox::class)->queue($portalUser, $edition, 'entry_submitted', $payload);

        $this->assertSame(1, FestivalNotification::query()->where('account_id', $account->id)->count());
        $this->assertSame($payload, FestivalNotification::query()->firstOrFail()->payload);
    }

    public function test_score_sheet_uses_assignment_boundaries_optimistic_locking_and_submission_lock(): void
    {
        [$account, $edition, $portalUser, $category] = $this->festival();
        $judge = User::factory()->create();
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        [$entry, $sheet, $criterion] = $this->sheet($account, $edition, $portalUser, $category, $assignment);

        $saved = app(SaveFestivalScoreSheet::class)->execute($sheet, $assignment, [
            'lock_version' => 1,
            'comments' => 'Private judge note',
            'scores' => [['criterion_id' => $criterion->id, 'score' => 8.5, 'comment' => 'Private criterion note']],
        ], $judge);
        $this->assertSame(2, $saved->lock_version);

        $this->expectException(ValidationException::class);
        app(SaveFestivalScoreSheet::class)->execute($saved, $assignment, ['lock_version' => 1, 'scores' => [['criterion_id' => $criterion->id, 'score' => 9]]], $judge);
    }

    public function test_submitted_score_sheet_is_locked_and_results_are_deterministic_with_private_comments(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $category] = $this->festival();
        $judge = User::factory()->create();
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        [$firstEntry, $firstSheet, $criterion] = $this->sheet($account, $edition, $portalUser, $category, $assignment);
        $firstSheet = app(SaveFestivalScoreSheet::class)->execute($firstSheet, $assignment, ['lock_version' => 1, 'comments' => 'SECRET-JUDGE-COMMENT', 'scores' => [['criterion_id' => $criterion->id, 'score' => 9, 'comment' => 'SECRET-CRITERION-COMMENT']], 'submit' => true], $judge);
        $this->assertSame(FestivalScoreSheetStatus::Locked, $firstSheet->status);

        $secondPortal = FestivalPortalUser::factory()->for($account)->create();
        [$secondEntry, $secondSheet] = $this->sheet($account, $edition, $secondPortal, $category, $assignment, $firstSheet->rubric);
        app(SaveFestivalScoreSheet::class)->execute($secondSheet, $assignment, ['lock_version' => 1, 'scores' => [['criterion_id' => $criterion->id, 'score' => 9]], 'submit' => true], $judge);
        FestivalPenalty::query()->create(['account_id' => $account->id, 'festival_entry_id' => $secondEntry->id, 'kind' => 'deduction', 'points' => 1, 'reason' => 'Time limit', 'created_by' => $judge->id]);

        app(PublishFestivalResults::class)->execute($edition, $category, $judge);
        $this->assertSame(1, $firstEntry->result()->firstOrFail()->rank);
        $this->assertSame(2, $secondEntry->result()->firstOrFail()->rank);

        $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))->assertOk()->assertSee($firstEntry->performer_name)->assertDontSee('SECRET-JUDGE-COMMENT')->assertDontSee('SECRET-CRITERION-COMMENT');
        $this->actingAs($portalUser, 'festival')->get(route('festival.portal.entries.show', [$account->slug, $firstEntry]))->assertOk()->assertSee('SECRET-CRITERION-COMMENT');

        try {
            app(SaveFestivalScoreSheet::class)->execute($firstSheet->refresh(), $assignment, ['lock_version' => $firstSheet->lock_version, 'scores' => [['criterion_id' => $criterion->id, 'score' => 10]]], $judge);
            $this->fail('Locked score sheet was edited.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    /** @return array{FestivalEntry, FestivalScoreSheet, FestivalRubricCriterion} */
    private function sheet(Account $account, FestivalEdition $edition, FestivalPortalUser $portalUser, FestivalCategory $category, FestivalJudgeAssignment $assignment, ?FestivalRubric $rubric = null): array
    {
        $entry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id, 'status' => 'accepted']);
        $rubric ??= FestivalRubric::factory()->for($edition)->create(['account_id' => $account->id, 'festival_category_id' => $category->id]);
        if ($rubric->sections()->doesntExist()) {
            $section = $rubric->sections()->create(['account_id' => $account->id, 'name' => 'Technique', 'weight' => 1]);
            $criterion = $section->criteria()->create(['account_id' => $account->id, 'name' => 'Execution', 'max_score' => 10, 'weight' => 1]);
        } else {
            $criterion = $rubric->sections()->firstOrFail()->criteria()->firstOrFail();
        }
        $sheet = FestivalScoreSheet::query()->create(['account_id' => $account->id, 'festival_entry_id' => $entry->id, 'festival_judge_assignment_id' => $assignment->id, 'festival_rubric_id' => $rubric->id]);

        return [$entry, $sheet, $criterion];
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser, FestivalCategory} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);

        return [$account, $edition, $portalUser, $category];
    }
}
