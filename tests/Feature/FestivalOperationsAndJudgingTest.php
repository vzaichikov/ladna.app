<?php

namespace Tests\Feature;

use App\Actions\Festivals\BuildFestivalResults;
use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Actions\Festivals\SaveFestivalScheduleSlot;
use App\Actions\Festivals\SaveFestivalScoreSheet;
use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalScoreSheetStatus;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalNotification;
use App\Models\FestivalPenalty;
use App\Models\FestivalPortalUser;
use App\Models\FestivalResult;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
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
        $notification = FestivalNotification::query()->firstOrFail();
        $this->assertSame(FestivalNotificationChannel::Email, $notification->channel);
        $this->assertSame($portalUser->email, $notification->recipient_email);
        $this->assertSame(__('app.festival_notification_subject_with_name', [
            'festival' => $edition->title,
            'subject' => __('app.festival_notification_template_entry_submitted_subject', locale: $portalUser->locale),
        ], $portalUser->locale), $notification->subject);
        $this->assertSame($payload['subject'], $notification->payload['subject']);
        $this->assertSame($edition->title, $notification->payload['festival']);
        $this->assertArrayHasKey('greeting', $notification->payload);
        $this->assertArrayHasKey('lines', $notification->payload);
    }

    public function test_score_sheet_uses_assignment_boundaries_and_remains_editable(): void
    {
        [$account, $edition, $portalUser, $category] = $this->festival();
        $judge = User::factory()->create();
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        [$entry, $sheet, $criterion] = $this->sheet($account, $edition, $portalUser, $category, $assignment);

        $saved = app(SaveFestivalScoreSheet::class)->execute($sheet, $assignment, [
            'comments' => 'Private judge note',
            'scores' => [['criterion_id' => $criterion->id, 'score' => 8.5, 'comment' => 'Private criterion note']],
        ], $judge);
        $this->assertSame(FestivalScoreSheetStatus::Submitted, $saved->status);
        $this->assertSame('8.5000', $saved->total_score);

        $resaved = app(SaveFestivalScoreSheet::class)->execute($saved, $assignment, ['scores' => [['criterion_id' => $criterion->id, 'score' => 9]]], $judge);
        $this->assertSame('9.0000', $resaved->total_score);
    }

    public function test_ready_score_sheet_remains_editable_and_becomes_incomplete_when_a_score_is_cleared(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $category] = $this->festival();
        $judge = User::factory()->create();
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        [$firstEntry, $firstSheet, $criterion] = $this->sheet($account, $edition, $portalUser, $category, $assignment);
        $firstSheet = app(SaveFestivalScoreSheet::class)->execute($firstSheet, $assignment, ['comments' => 'SECRET-JUDGE-COMMENT', 'scores' => [['criterion_id' => $criterion->id, 'score' => 9, 'comment' => 'SECRET-CRITERION-COMMENT']], 'submit' => true], $judge);
        $this->assertSame(FestivalScoreSheetStatus::Submitted, $firstSheet->status);

        $secondPortal = FestivalPortalUser::factory()->for($account)->create();
        [$secondEntry, $secondSheet] = $this->sheet($account, $edition, $secondPortal, $category, $assignment, $firstSheet->rubric);
        app(SaveFestivalScoreSheet::class)->execute($secondSheet, $assignment, ['scores' => [['criterion_id' => $criterion->id, 'score' => 9]], 'submit' => true], $judge);
        FestivalPenalty::query()->create(['account_id' => $account->id, 'festival_entry_id' => $secondEntry->id, 'kind' => 'deduction', 'points' => 1, 'reason' => 'Time limit', 'created_by' => $judge->id]);

        $results = app(BuildFestivalResults::class)->execute($edition, $category);
        $this->assertSame($firstEntry->id, $results['rows'][0]['entry']->id);
        $this->assertSame(1, $results['rows'][0]['rank']);
        $this->assertSame($secondEntry->id, $results['rows'][1]['entry']->id);
        $this->assertSame(2, $results['rows'][1]['rank']);

        $corrected = app(SaveFestivalScoreSheet::class)->execute($firstSheet->refresh(), $assignment, ['scores' => [['criterion_id' => $criterion->id, 'score' => 10]]], $judge);
        $this->assertSame('10.0000', $corrected->total_score);
        $this->assertSame(FestivalScoreSheetStatus::Submitted, $corrected->status);
        $recalculated = app(BuildFestivalResults::class)->execute($edition, $category);
        $this->assertSame('10.0000', $recalculated['rows'][0]['total']);
        $this->assertDatabaseMissing('festival_results', ['festival_entry_id' => $firstEntry->id]);
        $this->assertDatabaseMissing('festival_results', ['festival_entry_id' => $secondEntry->id]);
        $this->assertDatabaseHas('festival_activity_logs', [
            'subject_type' => FestivalScoreSheet::class,
            'subject_id' => $firstSheet->id,
            'action' => 'score_sheet.saved',
        ]);

        $incomplete = app(SaveFestivalScoreSheet::class)->execute($corrected, $assignment, ['scores' => [['criterion_id' => $criterion->id, 'score' => null]]], $judge);
        $this->assertSame('0.0000', $incomplete->total_score);
        $this->assertSame(FestivalScoreSheetStatus::Draft, $incomplete->status);
        $this->assertNull($incomplete->submitted_at);
        $this->assertDatabaseHas('festival_criterion_scores', [
            'festival_score_sheet_id' => $firstSheet->id,
            'festival_rubric_criterion_id' => $criterion->id,
            'score' => null,
            'comment' => 'SECRET-CRITERION-COMMENT',
        ]);
    }

    public function test_rubric_update_resets_affected_score_sheets_and_results(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $category] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $judge = User::factory()->create();
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        [$entry, $sheet, $criterion] = $this->sheet($account, $edition, $portalUser, $category, $assignment);
        app(SaveFestivalScoreSheet::class)->execute($sheet, $assignment, ['scores' => [['criterion_id' => $criterion->id, 'score' => 9]], 'submit' => true], $judge);
        FestivalResult::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_entry_id' => $entry->id,
            'total_score' => 9,
            'rank' => 1,
            'published_at' => now(),
        ]);

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.judging.criteria.update', [$account, $edition, $sheet->rubric]), [
            'festival_category_id' => $category->id,
            'name' => 'Updated rubric',
            'sections' => [[
                'name' => 'Artistry',
                'weight' => 1,
                'criteria' => [['name' => 'Composition', 'max_score' => 12, 'weight' => 1]],
            ]],
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition]))->assertSessionHasNoErrors();

        $this->assertSame(FestivalScoreSheetStatus::Draft, $sheet->refresh()->status);
        $this->assertSame('0.0000', $sheet->total_score);
        $this->assertDatabaseMissing('festival_criterion_scores', ['festival_score_sheet_id' => $sheet->id]);
        $this->assertDatabaseMissing('festival_results', ['festival_entry_id' => $entry->id]);
        $this->assertDatabaseMissing('festival_rubric_criteria', ['id' => $criterion->id]);
        $this->assertDatabaseHas('festival_rubric_criteria', ['name' => 'Composition', 'max_score' => 12]);
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
