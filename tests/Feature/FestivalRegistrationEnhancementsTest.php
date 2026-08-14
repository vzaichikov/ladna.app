<?php

namespace Tests\Feature;

use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Actions\Festivals\ReassignFestivalEntryCategory;
use App\Actions\Festivals\ReviewFestivalEntryStep;
use App\Actions\Festivals\StoreFestivalResponse;
use App\Actions\Festivals\SubmitFestivalEntryStep;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalQualificationStatus;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalParticipant;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalResult;
use App\Models\FestivalRubric;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\FestivalWorkflow;
use App\Models\User;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalRegistrationEnhancementsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_first_application_submission_uses_submission_date_for_age_rules(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'min_age' => 18]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'date_of_birth' => now()->subYears(18)->addDay()->toDateString(),
        ]);
        $entry = $this->entry($category, $portalUser, [$participant]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);

        $this->expectException(ValidationException::class);
        app(SubmitFestivalEntryStep::class)->execute($entry, $entry->steps->first());
    }

    public function test_first_application_submission_accepts_exact_adult_and_masters_age_boundaries(): void
    {
        [$account, $edition, $portalUser] = $this->festival();

        foreach ([18, 35] as $minimumAge) {
            $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'min_age' => $minimumAge]);
            $participant = FestivalParticipant::factory()->for($portalUser)->create([
                'account_id' => $account->id,
                'date_of_birth' => now()->subYears($minimumAge)->toDateString(),
            ]);
            $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($category, $portalUser, [$participant]));

            app(SubmitFestivalEntryStep::class)->execute($entry, $entry->steps->first());

            $this->assertNotNull($entry->refresh()->submitted_at);
        }
    }

    public function test_url_response_honours_configured_hosts_and_subdomains(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'code' => 'qualification-video',
            'type' => 'qualification_video',
            'input_type' => 'url',
            'subject_scope' => 'entry',
            'stage' => 'qualification',
            'validation' => ['allowed_hosts' => ['youtube.com', 'instagram.com']],
        ]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($category, $portalUser, [$participant]));
        $requirement = $entry->requirements->first();

        $acceptedUrls = [
            'https://www.youtube.com/watch?v=one',
            'https://www.instagram.com/reel/ABC123/',
            'https://instagram.com/p/DEF456/',
            'https://www.instagram.com/share/reel/GHI789/',
            'https://www.instagram.com/share/p/MNO345/?utm_source=ig_web_copy_link',
            'https://m.instagram.com/p/JKL012/',
            'https://l.instagram.com/?u=https%3A%2F%2Fwww.instagram.com%2Freel%2FPQR678%2F',
        ];

        foreach ($acceptedUrls as $acceptedUrl) {
            app(StoreFestivalResponse::class)->execute($requirement, $portalUser, $acceptedUrl);
            $this->assertSame($acceptedUrl, data_get($requirement->submissions()->first()->value_json, 'value'));
        }

        foreach (['https://example.com/video', 'https://evilinstagram.com/reel/ABC123', 'https://instagram.com.example/p/DEF456'] as $rejectedUrl) {
            try {
                app(StoreFestivalResponse::class)->execute($requirement, $portalUser, $rejectedUrl);
                $this->fail("The unconfigured qualification video host in {$rejectedUrl} should be rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('value', $exception->errors());
            }
        }
    }

    public function test_qualification_approval_activates_roster_priced_charge_with_capped_relative_deadline(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser] = $this->festival();
        $account->update(['default_currency' => 'USD']);
        $edition->update(['currency' => 'UAH']);
        $workflow = $this->workflow($edition);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'festival_workflow_id' => $workflow->id, 'min_members' => 1, 'max_members' => 10]);
        $paymentStep = $workflow->steps->firstWhere('code', 'participation_payment');
        $hardCap = now()->addDays(3)->startOfMinute();
        FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
            'festival_workflow_step_id' => $paymentStep->id,
            'amount_cents' => 320000,
            'pricing_mode' => 'roster',
            'included_members' => 2,
            'additional_member_amount_cents' => 40000,
            'due_policy' => 'approval_relative',
            'due_days_after_approval' => 5,
            'due_hard_cap_at' => $hardCap,
        ]);
        $participants = FestivalParticipant::factory()->count(4)->for($portalUser)->create(['account_id' => $account->id]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($category, $portalUser, $participants->all()));
        $application = $entry->steps->first(fn ($step): bool => $step->workflowStep->code === 'application');
        $application->update(['status' => FestivalEntryStepStatus::Submitted]);

        app(ReviewFestivalEntryStep::class)->execute($application, User::factory()->create(), 'approve');

        $charge = $entry->charges()->where('kind', 'participation')->firstOrFail();
        $this->assertSame(400000, $charge->amount_cents);
        $this->assertSame('USD', $charge->currency);
        $this->assertSame($hardCap->timestamp, $charge->due_at->timestamp);
        $this->assertSame(FestivalChargeStatus::Pending, $charge->status);
        $this->assertSame(FestivalQualificationStatus::Passed, $entry->refresh()->qualification_status);
    }

    public function test_technical_step_reserves_normalized_track_and_withdrawal_releases_it(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $workflow = $this->workflow($edition);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'festival_workflow_id' => $workflow->id]);
        $technicalStep = $workflow->steps->firstWhere('code', 'technical_form');
        foreach (['music_artist' => 'Artist', 'music_title' => 'Track title'] as $code => $name) {
            FestivalRequirementDefinition::factory()->for($edition)->create([
                'account_id' => $account->id,
                'festival_workflow_step_id' => $technicalStep->id,
                'code' => $code,
                'name' => $name,
                'type' => 'custom_document',
                'input_type' => 'short_text',
                'subject_scope' => 'entry',
                'stage' => 'final',
            ]);
        }

        $first = $this->trackEntry($category, $portalUser, '  Beyoncé ', ' Halo  ');
        $this->assertSame('Beyoncé', $first->track_artist);
        $this->assertSame('Halo', $first->track_title);
        $this->assertNotNull($first->normalized_track_key);

        try {
            $this->trackEntry($category, $portalUser, 'BEYONCÉ', 'halo');
            $this->fail('The same normalized track should not be reserved twice in one category.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('step', $exception->errors());
        }

        $this->actingAs($portalUser, 'festival')
            ->post(route('festival.portal.entries.withdraw', [$account->slug, $first]))
            ->assertRedirect();
        $this->assertNull($first->refresh()->normalized_track_key);

        $replacement = $this->trackEntry($category, $portalUser, 'BEYONCÉ', 'HALO');
        $this->assertNotNull($replacement->normalized_track_key);
    }

    public function test_expired_charge_cannot_start_checkout_and_late_callback_requires_refund(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = $this->entry($category, $portalUser, []);
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'code' => 'FCH-DEADLINE',
            'kind' => 'participation',
            'name' => 'Participation',
            'amount_cents' => 290000,
            'currency' => 'UAH',
            'due_at' => now()->subMinute(),
        ]);

        try {
            app(FestivalPaymentService::class)->startCharge($charge, 'monopay');
            $this->fail('Checkout must not start after the charge deadline.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('provider', $exception->errors());
        }

        $attempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'provider' => 'monopay',
            'order_id' => 'FCHP-LATE',
            'amount_cents' => 290000,
            'currency' => 'UAH',
            'expires_at' => now()->addMinutes(10),
        ]);
        app(FestivalPaymentService::class)->completeAttempt($attempt, new PaymentCallbackResult(
            orderId: 'FCHP-LATE',
            status: PaymentCallbackStatus::Paid,
            amountCents: 290000,
            currency: 'UAH',
        ));

        $this->assertSame(FestivalChargeStatus::PaidRequiresRefund, $charge->refresh()->status);
    }

    public function test_editable_roster_reprices_until_checkout_has_started(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $workflow = $this->workflow($edition);
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
            'min_members' => 1,
            'max_members' => 3,
        ]);
        FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
            'festival_workflow_step_id' => $workflow->steps->firstWhere('code', 'application')->id,
            'kind' => 'qualification',
            'amount_cents' => 50000,
            'pricing_mode' => 'roster',
            'included_members' => 1,
            'additional_member_amount_cents' => 10000,
        ]);
        $participants = FestivalParticipant::factory()->count(2)->for($portalUser)->create(['account_id' => $account->id]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($category, $portalUser, [$participants[0]]));
        $charge = $entry->charges()->firstOrFail();

        $payload = [
            'festival_category_id' => $category->id,
            'participant_ids' => $participants->modelKeys(),
            'entry_name' => $entry->entry_name,
        ];
        $this->actingAs($portalUser, 'festival')
            ->put(route('festival.portal.entries.update', [$account->slug, $entry]), $payload)
            ->assertRedirect();
        $this->assertSame(60000, $charge->refresh()->amount_cents);

        FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'provider' => 'monopay',
            'order_id' => 'FCHP-ROSTER',
            'amount_cents' => 60000,
            'currency' => 'UAH',
            'expires_at' => now()->addMinutes(30),
        ]);
        $payload['participant_ids'] = [$participants[0]->id];
        $this->actingAs($portalUser, 'festival')
            ->from(route('festival.portal.entries.edit', [$account->slug, $entry]))
            ->put(route('festival.portal.entries.update', [$account->slug, $entry]), $payload)
            ->assertSessionHasErrors('participant_ids');
        $this->assertSame(2, $entry->participants()->count());
        $this->assertSame(60000, $charge->refresh()->amount_cents);
    }

    public function test_staff_reassignment_preserves_qualification_and_replaces_pending_participation_charge(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser] = $this->festival();
        $workflow = $this->workflow($edition);
        $source = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'festival_workflow_id' => $workflow->id]);
        $target = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'festival_workflow_id' => $workflow->id]);
        $paymentStep = $workflow->steps->firstWhere('code', 'participation_payment');
        foreach ([[$source, 290000], [$target, 320000]] as [$category, $amount]) {
            FestivalChargeDefinition::factory()->for($edition)->create([
                'account_id' => $account->id,
                'festival_category_id' => $category->id,
                'festival_workflow_step_id' => $paymentStep->id,
                'amount_cents' => $amount,
                'due_policy' => 'approval_relative',
                'due_days_after_approval' => 5,
            ]);
        }
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($source, $portalUser, [$participant]));
        $application = $entry->steps->first(fn ($step): bool => $step->workflowStep->code === 'application');
        $application->update(['status' => FestivalEntryStepStatus::Submitted]);
        $manager = User::factory()->create();
        app(ReviewFestivalEntryStep::class)->execute($application, $manager, 'approve');
        $sourceCharge = $entry->charges()->where('kind', 'participation')->firstOrFail();

        $attempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $sourceCharge->id,
            'provider' => 'monopay',
            'order_id' => 'FCHP-REASSIGN',
            'amount_cents' => $sourceCharge->amount_cents,
            'currency' => $sourceCharge->currency,
            'expires_at' => now()->addMinutes(30),
        ]);
        try {
            app(ReassignFestivalEntryCategory::class)->execute($entry, $target, $manager, 'Organizer recommendation');
            $this->fail('A category cannot change while participation checkout is live.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('festival_category_id', $exception->errors());
        }
        $attempt->update(['status' => 'expired', 'expires_at' => now()->subMinute()]);

        app(ReassignFestivalEntryCategory::class)->execute($entry, $target, $manager, 'Organizer recommendation');

        $this->assertSame($target->id, $entry->refresh()->festival_category_id);
        $this->assertSame(FestivalQualificationStatus::Passed, $entry->qualification_status);
        $this->assertSame(FestivalChargeStatus::Cancelled, $sourceCharge->refresh()->status);
        $this->assertSame(320000, $entry->charges()->where('festival_charge_definition_id', '!=', $sourceCharge->festival_charge_definition_id)->firstOrFail()->amount_cents);
    }

    public function test_accepted_entry_cannot_be_reassigned_into_a_full_category(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $workflow = $this->workflow($edition);
        $source = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
        ]);
        $target = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
            'maximum_accepted_entries' => 1,
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry = $this->entry($source, $portalUser, [$participant]);
        $entry->forceFill([
            'status' => FestivalEntryStatus::Accepted,
            'accepted_at' => now(),
            'registration_completed_at' => now(),
        ])->save();
        $occupyingEntry = FestivalEntry::factory()->for($target)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::Accepted,
            'accepted_at' => now(),
            'registration_completed_at' => now(),
        ]);
        $manager = User::factory()->create();

        try {
            app(ReassignFestivalEntryCategory::class)->execute($entry, $target, $manager, 'Move to target category.');
            $this->fail('An accepted entry must not exceed the target category capacity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('festival_category_id', $exception->errors());
        }
        $this->assertSame($source->id, $entry->refresh()->festival_category_id);

        $occupyingEntry->forceFill(['status' => FestivalEntryStatus::Rejected, 'registration_completed_at' => null])->save();
        app(ReassignFestivalEntryCategory::class)->execute($entry, $target, $manager, 'Move after a place becomes free.');
        $this->assertSame($target->id, $entry->refresh()->festival_category_id);
    }

    public function test_staff_reassignment_stops_after_judging_or_results_exist(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $workflow = $this->workflow($edition);
        $source = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'festival_workflow_id' => $workflow->id]);
        $target = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'festival_workflow_id' => $workflow->id]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($source, $portalUser, [$participant]));
        $manager = User::factory()->create();
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($manager)->create(['account_id' => $account->id]);
        $rubric = FestivalRubric::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $source->id,
        ]);
        $sheet = FestivalScoreSheet::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'festival_judge_assignment_id' => $assignment->id,
            'festival_rubric_id' => $rubric->id,
        ]);

        try {
            app(ReassignFestivalEntryCategory::class)->execute($entry, $target, $manager, 'Too late after judging.');
            $this->fail('A category changed after a score sheet was prepared.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('app.festival_category_reassignment_judging_started'),
                $exception->errors()['festival_category_id'][0] ?? null,
            );
        }
        $this->assertSame($source->id, $entry->refresh()->festival_category_id);

        $sheet->delete();
        $result = FestivalResult::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_entry_id' => $entry->id,
            'total_score' => 9,
            'rank' => 1,
            'published_at' => now(),
        ]);

        try {
            app(ReassignFestivalEntryCategory::class)->execute($entry, $target, $manager, 'Too late after results.');
            $this->fail('A category changed after a result was published.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('app.festival_category_reassignment_judging_started'),
                $exception->errors()['festival_category_id'][0] ?? null,
            );
        }
        $this->assertSame($source->id, $entry->refresh()->festival_category_id);

        $result->delete();
        app(ReassignFestivalEntryCategory::class)->execute($entry, $target, $manager, 'Valid before competition progress.');
        $this->assertSame($target->id, $entry->refresh()->festival_category_id);
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'age_reference_date' => now()->addYear()->toDateString(),
            'timezone' => 'Europe/Kyiv',
        ]);

        return [$account, $edition, FestivalPortalUser::factory()->for($account)->create()];
    }

    private function workflow(FestivalEdition $edition): FestivalWorkflow
    {
        return app(ProvisionFestivalWorkflow::class)->execute($edition, 'Registration');
    }

    /** @param array<int, FestivalParticipant> $participants */
    private function entry(FestivalCategory $category, FestivalPortalUser $portalUser, array $participants): FestivalEntry
    {
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $category->account_id,
            'festival_edition_id' => $category->festival_edition_id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        $entry->participants()->sync(collect($participants)->values()->mapWithKeys(fn (FestivalParticipant $participant, int $index): array => [
            $participant->id => ['account_id' => $category->account_id, 'sort_order' => $index],
        ])->all());

        return $entry;
    }

    private function trackEntry(FestivalCategory $category, FestivalPortalUser $portalUser, string $artist, string $title): FestivalEntry
    {
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $category->account_id]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($category, $portalUser, [$participant]));
        $application = $entry->steps->first(fn ($step): bool => $step->workflowStep->code === 'application');
        $application->update(['status' => FestivalEntryStepStatus::Approved]);
        $payment = $entry->steps->first(fn ($step): bool => $step->workflowStep->code === 'participation_payment');
        app(SubmitFestivalEntryStep::class)->execute($entry, $payment);
        $technical = $entry->steps->first(fn ($step): bool => $step->workflowStep->code === 'technical_form');

        foreach ($technical->requirements as $requirement) {
            app(StoreFestivalResponse::class)->execute(
                $requirement,
                $portalUser,
                $requirement->definition->code === 'music_artist' ? $artist : $title,
            );
        }
        app(SubmitFestivalEntryStep::class)->execute($entry, $technical);

        return $entry->refresh();
    }
}
