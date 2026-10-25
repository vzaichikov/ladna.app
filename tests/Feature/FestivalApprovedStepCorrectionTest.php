<?php

namespace Tests\Feature;

use App\Actions\Festivals\FullyConfirmFestivalEntry;
use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Actions\Festivals\ReviewFestivalEntryStep;
use App\Actions\Festivals\StoreFestivalResponse;
use App\Actions\Festivals\StoreFestivalSubmission;
use App\Actions\Festivals\SubmitFestivalEntryStep;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Http\Middleware\PreventExpiredSubscriptionMutations;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalEntryStep;
use App\Models\FestivalNotification;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FestivalApprovedStepCorrectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_reopens_only_the_approved_technical_step_and_leaves_the_music_unchanged(): void
    {
        [$account, $edition, $entry, $step, $owner, , $music] = $this->fixture();
        $otherSteps = $entry->steps()->where('id', '!=', $step->id)->get()->map->getRawOriginal()->all();
        $original = $music->submissions()->sole()->getRawOriginal();
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]))
            ->assertOk()->assertSee('data-festival-approved-step-correction="'.$step->id.'"', false);
        $this->patchJson($this->reviewUrl($account, $edition, $entry, $step), $this->correction())
            ->assertOk()->assertJsonStructure(['message', 'fragments_html']);
        $this->assertSame(FestivalEntryStepStatus::ChangesRequested, $step->refresh()->status);
        $this->assertSame(FestivalEntryStatus::UnderReview, $entry->refresh()->status);
        $this->assertSame('passed', $entry->qualification_status->value);
        $this->assertSame($otherSteps, $entry->steps()->where('id', '!=', $step->id)->get()->map->getRawOriginal()->all());
        $this->assertSame($original, $music->submissions()->sole()->getRawOriginal());
        $this->assertTrue($entry->activityLogs()->where('action', 'entry_step.request_changes')->exists());
        $this->assertTrue(FestivalNotification::query()->where('festival_entry_id', $entry->id)->where('type', 'entry_step_reviewed')->exists());
    }

    public function test_accepted_entry_allows_file_and_text_correction_until_submit_and_keeps_paid_and_completed_facts(): void
    {
        [$account, $edition, $entry, $step, $owner, $portal, $music, $note] = $this->fixture(true);
        $completedAt = $entry->registration_completed_at;
        $acceptedAt = $entry->accepted_at;
        $summary = $entry->steps->firstWhere('workflowStep.code', 'summary');
        $paid = $entry->charges()->create([
            'account_id' => $account->id, 'festival_entry_step_id' => $entry->steps->firstWhere('workflowStep.code', 'participation_payment')->id,
            'code' => 'CORRECTION-'.$entry->id, 'kind' => 'participation', 'name' => 'Participation',
            'status' => 'paid', 'paid_at' => now()->subDay(), 'amount_cents' => 290000, 'currency' => 'UAH',
        ]);
        $paidFacts = $paid->refresh()->getRawOriginal();
        foreach ([$music, $note] as $requirement) {
            $requirement->definition->update(['due_at' => now()->subDays(2), 'validation' => ['allow_post_confirmation_edits' => false]]);
        }
        $step->workflowStep->update(['due_at' => now()->subDay()]);
        app(ReviewFestivalEntryStep::class)->execute($step, $owner, 'request_changes', 'Replace music and note.', now()->addDay()->toDateTimeString());
        $this->assertSame(FestivalEntryStatus::ChangesPending, $entry->refresh()->status);
        $this->actingAs($portal, 'festival')->get(route('festival.portal.entries.show', [$account->slug, $entry]))
            ->assertOk()->assertSee(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]), false);
        $this->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]))
            ->assertOk()->assertSee('data-festival-file-upload', false)->assertSee('festival-entry-step-submit-'.$step->id, false);
        $oldPath = $music->submissions()->sole()->path;
        app(StoreFestivalSubmission::class)->execute($music->refresh(), $portal, UploadedFile::fake()->create('replacement.mp3', 20, 'audio/mpeg'));
        app(StoreFestivalResponse::class)->execute($note->refresh(), $portal, 'Updated technical note');
        $this->assertSame(FestivalEntryStepStatus::ChangesRequested, $step->refresh()->status);
        $this->assertSame('replacement.mp3', $music->submissions()->sole()->original_name);
        Storage::disk('local')->assertMissing($oldPath);
        app(SubmitFestivalEntryStep::class)->execute($entry->refresh(), $step->refresh());
        $this->assertSame(FestivalEntryStepStatus::Submitted, $step->refresh()->status);
        $this->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $note]), ['value' => 'After Submit'])->assertStatus(409);
        app(ReviewFestivalEntryStep::class)->execute($step->refresh(), $owner, 'approve');
        app(FullyConfirmFestivalEntry::class)->execute($entry->refresh(), $owner);
        $this->assertSame(FestivalEntryStatus::Accepted, $entry->refresh()->status);
        $this->assertTrue($entry->registration_completed_at->equalTo($completedAt));
        $this->assertTrue($entry->accepted_at->equalTo($acceptedAt));
        $this->assertSame(FestivalEntryStepStatus::Approved, $summary->refresh()->status);
        $this->assertSame($paidFacts, $paid->refresh()->getRawOriginal());
    }

    public function test_expired_correction_blocks_file_text_and_submit_even_with_general_editing_permission(): void
    {
        [$account, , $entry, $step, $owner, $portal, $music, $note] = $this->fixture(true);
        foreach ([$music, $note] as $requirement) {
            $requirement->definition->update(['validation' => ['allow_post_confirmation_edits' => true, 'editable_until_rule' => ['reference' => 'starts_at', 'offset_days' => 10]]]);
        }
        app(ReviewFestivalEntryStep::class)->execute($step, $owner, 'request_changes', 'Correction expires soon.', now()->addMinute()->toDateTimeString());
        $this->travel(2)->minutes();
        $this->actingAs($portal, 'festival')->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $note]), ['value' => 'Too late'])->assertStatus(409);
        $this->postJson(route('festival.portal.submissions.store', [$account->slug, $entry, $music]), ['file' => UploadedFile::fake()->create('late.mp3', 20, 'audio/mpeg')])->assertStatus(409);
        $this->post(route('festival.portal.entry-steps.submit', [$account->slug, $entry, $step]))->assertStatus(409);
        $this->travelBack();
    }

    public function test_automatic_forms_require_manual_correction_review_and_repeat_requests_preserve_later_approvals(): void
    {
        [, , $entry, $step, $owner] = $this->fixture();
        $step->workflowStep->update(['review_mode' => 'automatic']);
        $summary = $entry->steps->firstWhere('workflowStep.code', 'summary');
        $summary->update(['status' => 'approved']);
        app(ReviewFestivalEntryStep::class)->execute($step, $owner, 'request_changes', 'Replace music.', now()->addDay()->toDateTimeString());
        app(SubmitFestivalEntryStep::class)->execute($entry->refresh(), $step->refresh());
        $this->assertSame(FestivalEntryStepStatus::Submitted, $step->refresh()->status);
        app(ReviewFestivalEntryStep::class)->execute($step, $owner, 'request_changes', 'Another correction.', now()->addDay()->toDateTimeString());
        $this->assertSame(FestivalEntryStepStatus::Approved, $summary->refresh()->status);
    }

    public function test_reopen_validates_reason_and_deadline_and_interprets_owner_local_time(): void
    {
        [$account, $edition, $entry, $step, $owner] = $this->fixture();
        $url = $this->reviewUrl($account, $edition, $entry, $step);
        $this->actingAs($owner)->patchJson($url, ['decision' => 'request_changes'])->assertUnprocessable()->assertJsonValidationErrors(['comment', 'correction_due_at']);
        $this->patchJson($url, array_replace($this->correction(), ['correction_due_at' => now()->subMinute()->toDateTimeString()]))->assertUnprocessable()->assertJsonValidationErrors('correction_due_at');
        $deadline = now()->addDay()->startOfMinute();
        $this->patchJson($url, array_replace($this->correction(), ['correction_due_at' => $deadline->copy()->timezone($edition->timezone)->format('Y-m-d\TH:i')]))->assertOk();
        $this->assertTrue($step->refresh()->correction_due_at->equalTo($deadline));
        $notification = FestivalNotification::query()->where('festival_entry_id', $entry->id)->where('type', 'entry_step_reviewed')->latest('id')->firstOrFail();
        $this->assertStringContainsString($deadline->copy()->timezone($edition->timezone)->format('d.m.Y H:i').' '.$edition->timezone, $notification->text);
    }

    public function test_existing_configured_self_service_permission_remains_independent_after_correction_submission(): void
    {
        [, , $entry, $step, $owner, $portal, , $note] = $this->fixture(true);
        $note->definition->update(['validation' => [
            'allow_post_confirmation_edits' => true,
            'editable_until_rule' => ['reference' => 'starts_at', 'offset_days' => 10],
        ]]);
        app(ReviewFestivalEntryStep::class)->execute($step, $owner, 'request_changes', 'Update the note.', now()->addDay()->toDateTimeString());
        app(SubmitFestivalEntryStep::class)->execute($entry->refresh(), $step->refresh());
        app(StoreFestivalResponse::class)->execute($note->refresh(), $portal, 'Still allowed by separate field settings.');
        $this->assertSame(FestivalEntryStepStatus::Submitted, $step->refresh()->status);
        $this->assertSame('Still allowed by separate field settings.', $note->submissions()->sole()->value_json['value']);
    }

    public function test_nonexistent_festival_local_deadline_is_rejected(): void
    {
        [$account, $edition, $entry, $step, $owner] = $this->fixture();
        $edition->update(['timezone' => 'Europe/Kyiv']);
        $this->actingAs($owner)->patchJson($this->reviewUrl($account, $edition, $entry, $step), array_replace($this->correction(), [
            'correction_due_at' => '2027-03-28T03:30',
        ]))->assertUnprocessable()->assertJsonValidationErrors('correction_due_at');
        $this->assertSame(FestivalEntryStepStatus::Approved, $step->refresh()->status);
    }

    #[DataProvider('unsupportedSteps')]
    public function test_approved_nontechnical_steps_cannot_be_reopened(string $code): void
    {
        [$account, $edition, $entry, , $owner] = $this->fixture(true);
        $step = $entry->steps->firstWhere('workflowStep.code', $code);
        $this->actingAs($owner)->patchJson($this->reviewUrl($account, $edition, $entry, $step), $this->correction())->assertStatus(409);
        $this->assertSame(FestivalEntryStepStatus::Approved, $step->refresh()->status);
    }

    public static function unsupportedSteps(): array
    {
        return [['application'], ['participation_payment'], ['summary']];
    }

    #[DataProvider('closedStatuses')]
    public function test_closed_entries_cannot_be_reopened(string $status): void
    {
        [$account, $edition, $entry, $step, $owner] = $this->fixture();
        $entry->update(['status' => $status]);
        $this->actingAs($owner)->patchJson($this->reviewUrl($account, $edition, $entry, $step), $this->correction())->assertStatus(409);
    }

    public static function closedStatuses(): array
    {
        return [['rejected'], ['withdrawn'], ['draft']];
    }

    public function test_reopening_requires_registration_permission_and_the_exact_account(): void
    {
        [$account, $edition, $entry, $step] = $this->fixture();
        $outsider = User::factory()->create();
        $other = Account::factory()->create(['enable_festivals' => true]);
        $other->addOwner($outsider);
        $this->actingAs($outsider)->patchJson($this->reviewUrl($account, $edition, $entry, $step), $this->correction())->assertForbidden();
        $this->patchJson($this->reviewUrl($other, $edition, $entry, $step), $this->correction())->assertNotFound();
        $this->assertSame(FestivalEntryStepStatus::Approved, $step->refresh()->status);
    }

    /** @return array{Account, FestivalEdition, FestivalEntry, FestivalEntryStep, User, FestivalPortalUser, FestivalEntryRequirement, FestivalEntryRequirement} */
    private function fixture(bool $accepted = false): array
    {
        Queue::fake();
        Storage::fake('local');
        $this->withoutMiddleware(PreventExpiredSubscriptionMutations::class);
        $account = Account::factory()->create(['enable_festivals' => true]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Technical corrections');
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'festival_workflow_id' => $workflow->id]);
        $portal = FestivalPortalUser::factory()->for($account)->create();
        $participant = FestivalParticipant::factory()->for($portal)->create(['account_id' => $account->id]);
        $technical = $workflow->steps->firstWhere('code', 'technical_form');
        foreach (['music' => 'file', 'note' => 'short_text'] as $code => $inputType) {
            FestivalRequirementDefinition::factory()->for($edition)->create([
                'account_id' => $account->id, 'festival_workflow_step_id' => $technical->id, 'code' => $code,
                'type' => 'custom_document', 'subject_scope' => 'entry', 'input_type' => $inputType,
                'validation' => ['allow_post_confirmation_edits' => false], 'pricing' => ['mode' => 'none'], 'due_at' => now()->addDay(),
            ]);
        }
        $entry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portal->id, 'status' => 'under_review', 'qualification_status' => 'passed']);
        $entry->participants()->sync([$participant->id => ['account_id' => $account->id, 'sort_order' => 0]]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $entry->steps()->update(['status' => 'approved']);
        $step = $entry->steps->firstWhere('festival_workflow_step_id', $technical->id);
        $step->refresh()->update(['status' => 'draft']);
        $music = $step->requirements()->whereHas('definition', fn ($query) => $query->where('code', 'music'))->sole();
        $note = $step->requirements()->whereHas('definition', fn ($query) => $query->where('code', 'note'))->sole();
        app(StoreFestivalSubmission::class)->execute($music, $portal, UploadedFile::fake()->create('original.mp3', 10, 'audio/mpeg'));
        app(StoreFestivalResponse::class)->execute($note, $portal, 'Original note');
        $step->requirements()->update(['status' => 'accepted']);
        $step->update(['status' => 'approved', 'reviewed_by' => $owner->id, 'reviewed_at' => now()]);
        $entry->steps()->whereHas('workflowStep', fn ($query) => $query->where('type', 'summary'))->update(['status' => $accepted ? 'approved' : 'draft']);
        if ($accepted) {
            $entry->update(['status' => 'accepted', 'accepted_at' => now()->subDay(), 'registration_completed_at' => now()->subDay()]);
        }

        return [$account, $edition, $entry->refresh()->load('steps.workflowStep'), $step->refresh(), $owner, $portal, $music, $note];
    }

    /** @return array{decision: string, comment: string, correction_due_at: string} */
    private function correction(): array
    {
        return ['decision' => 'request_changes', 'comment' => 'Replace the music file.', 'correction_due_at' => now()->addDay()->toDateTimeString()];
    }

    private function reviewUrl(Account $account, FestivalEdition $edition, FestivalEntry $entry, FestivalEntryStep $step): string
    {
        return route('dashboard.accounts.festivals.entry-steps.review', [$account, $edition, $entry, $step]);
    }
}
