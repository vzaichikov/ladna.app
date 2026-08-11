<?php

namespace Tests\Feature;

use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Actions\Festivals\ReviewFestivalEntryStep;
use App\Actions\Festivals\StoreFestivalResponse;
use App\Actions\Festivals\StoreFestivalSubmission;
use App\Actions\Festivals\SubmitFestivalEntryStep;
use App\Enums\AccountRole;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\StudioPermission;
use App\Http\Middleware\PreventExpiredSubscriptionMutations;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\FestivalWorkflow;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalRegistrationStepperTest extends TestCase
{
    use DatabaseTransactions;

    public function test_response_component_formats_frozen_typed_values_safely(): void
    {
        $render = fn (string $inputType, mixed $value, array $options = []): string => Blade::render(
            '<x-festivals.response-value :snapshot="$snapshot" :value="$value" />',
            ['snapshot' => ['input_type' => $inputType, 'options' => $options], 'value' => $value],
        );

        $this->assertStringContainsString('Short answer', $render('short_text', 'Short answer'));
        $this->assertStringContainsString("First line\nSecond line", $render('long_text', "First line\nSecond line"));
        $this->assertStringContainsString('12', $render('integer', 12));
        $this->assertStringContainsString(__('app.yes'), $render('boolean', true));
        $this->assertStringContainsString(__('app.no'), $render('boolean', false));
        $this->assertStringContainsString(__('app.not_set'), $render('short_text', null));

        $url = $render('url', 'https://video.example/qualification');
        $this->assertStringContainsString('href="https://video.example/qualification"', $url);
        $this->assertStringContainsString('target="_blank"', $url);
        $this->assertStringContainsString('rel="noopener noreferrer"', $url);
        $this->assertStringNotContainsString('href=', $render('url', 'javascript:alert(1)'));

        $options = [
            ['value' => 'solo', 'label' => 'Solo label'],
            ['value' => 'duo', 'label' => 'Duo label'],
        ];
        $this->assertStringContainsString('Solo label', $render('single_select', 'solo', $options));
        $this->assertStringContainsString('Solo label, Duo label', $render('multi_select', ['solo', 'duo'], $options));
        $this->assertStringContainsString('legacy-value', $render('single_select', 'legacy-value', $options));

        $hostile = $render('long_text', '<script>alert("unsafe")</script>');
        $this->assertStringContainsString('&lt;script&gt;', $hostile);
        $this->assertStringNotContainsString('<script>', $hostile);
    }

    public function test_owner_review_renders_only_current_values_and_file_downloads_while_portal_withdraw_is_touch_safe(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $urlDefinition = $this->requirement($edition, $workflow, 'application', 'selection-video', 'url');
        $fileDefinition = $this->requirement($edition, $workflow, 'application', 'private-proof', 'file');
        $fileDefinition->forceFill([
            'allowed_extensions' => ['png'],
            'allowed_mime_types' => ['image/png'],
        ])->save();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'Review response entry'));
        $applicationStep = $entry->steps->firstWhere('code', 'application');
        $urlRequirement = $applicationStep->requirements->firstWhere('festival_requirement_definition_id', $urlDefinition->id);
        $fileRequirement = $applicationStep->requirements->firstWhere('festival_requirement_definition_id', $fileDefinition->id);

        $firstValue = app(StoreFestivalResponse::class)->execute($urlRequirement, $portalUser, 'https://video.example/original');
        $latestValue = app(StoreFestivalResponse::class)->execute($urlRequirement, $portalUser, 'https://video.example/revised');
        $fileSubmission = app(StoreFestivalSubmission::class)->execute($fileRequirement, $portalUser, UploadedFile::fake()->image('private-proof.png'));

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $ownerResponse = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications', [$account, $edition]));

        $ownerResponse->assertOk()
            ->assertSee('https://video.example/revised')
            ->assertDontSee('https://video.example/original')
            ->assertSee(route('dashboard.accounts.festivals.submissions.download', [$account, $fileSubmission]), false)
            ->assertDontSee(route('dashboard.accounts.festivals.submissions.download', [$account, $firstValue]), false)
            ->assertDontSee(route('dashboard.accounts.festivals.submissions.download', [$account, $latestValue]), false);
        $this->assertSame($firstValue->id, $latestValue->id);
        $this->assertCount(1, $urlRequirement->submissions()->get());

        $financeStaff = User::factory()->create();
        $account->users()->attach($financeStaff->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageFestivalFinance->value],
        ]);
        $this->actingAs($financeStaff)
            ->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('https://video.example/original')
            ->assertDontSee('https://video.example/revised');

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entries.show', [$account->slug, $entry]))
            ->assertOk()
            ->assertSeeInOrder([
                route('festival.portal.entries.withdraw', [$account->slug, $entry]),
                'min-h-11',
                __('app.festival_withdraw'),
            ], false);
    }

    public function test_four_step_registration_supports_revisions_priced_answers_and_completion(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $applicationDefinition = $this->requirement($edition, $workflow, 'application', 'selection-video', 'url');
        $technicalDefinition = $this->requirement($edition, $workflow, 'technical_form', 'helpers', 'integer', ['mode' => 'per_unit', 'unit_amount_cents' => 2500]);
        $entry = $this->entry($account, $edition, $portalUser, $participant, $category, 'First category entry');

        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);

        $this->assertCount(4, $entry->steps);
        $application = $entry->steps->firstWhere('code', 'application');
        $payment = $entry->steps->firstWhere('code', 'participation_payment');
        $technical = $entry->steps->firstWhere('code', 'technical_form');
        $summary = $entry->steps->firstWhere('code', 'summary');
        $this->assertSame($applicationDefinition->id, $application->requirements->first()->festival_requirement_definition_id);
        $technicalRequirement = $technical->requirements->firstWhere('festival_requirement_definition_id', $technicalDefinition->id);

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $technical]))
            ->assertOk()
            ->assertSee(__('app.festival_step_locked_previous'));
        $this->actingAs($portalUser, 'festival')
            ->post(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $technical, $technicalRequirement]), ['value' => 1])
            ->assertStatus(409);

        app(StoreFestivalResponse::class)->execute($application->requirements->first(), $portalUser, 'https://video.example/first');
        app(SubmitFestivalEntryStep::class)->execute($entry, $application);
        $this->assertSame(FestivalEntryStepStatus::Submitted, $application->refresh()->status);
        $this->assertSame(FestivalEntryStepStatus::Draft, $payment->refresh()->status);

        $reviewer = User::factory()->create();
        $account->addOwner($reviewer);
        $this->withoutMiddleware(PreventExpiredSubscriptionMutations::class)
            ->actingAs($reviewer, 'web')->patch(route('dashboard.accounts.festivals.entry-steps.review', [$account, $edition, $entry, $application]), [
                'decision' => 'request_changes',
                'comment' => 'Replace the video link.',
                'revision_due_at' => now()->addDay()->toDateTimeString(),
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(FestivalEntryStepStatus::ChangesRequested, $application->refresh()->status);

        app(StoreFestivalResponse::class)->execute($application->requirements->first(), $portalUser, 'https://video.example/revised');
        $this->assertCount(1, $application->requirements->first()->submissions()->get());
        app(SubmitFestivalEntryStep::class)->execute($entry->refresh(), $application->refresh());
        app(ReviewFestivalEntryStep::class)->execute($application->refresh(), $reviewer, 'approve', 'Qualified.');

        app(SubmitFestivalEntryStep::class)->execute($entry->refresh(), $payment->refresh());
        app(StoreFestivalResponse::class)->execute($technicalRequirement, $portalUser, 2);
        $charge = $entry->charges()->where('festival_entry_requirement_id', $technicalRequirement->id)->firstOrFail();
        $this->assertSame(5000, $charge->amount_cents);

        try {
            app(SubmitFestivalEntryStep::class)->execute($entry->refresh(), $technical->refresh());
            $this->fail('An outstanding priced response charge must block the step.');
        } catch (ValidationException) {
            $this->assertSame(FestivalEntryStepStatus::Draft, $technical->refresh()->status);
        }

        $charge->forceFill(['status' => FestivalChargeStatus::Paid, 'paid_at' => now()])->save();
        app(SubmitFestivalEntryStep::class)->execute($entry->refresh(), $technical->refresh());
        app(ReviewFestivalEntryStep::class)->execute($technical->refresh(), $reviewer, 'approve');
        app(SubmitFestivalEntryStep::class)->execute($entry->refresh(), $summary->refresh());

        $this->assertSame(FestivalEntryStatus::Accepted, $entry->refresh()->status);
        $this->assertNotNull($entry->registration_completed_at);
        $this->assertSame(FestivalEntryStepStatus::Approved, $summary->refresh()->status);
    }

    public function test_per_participant_entry_limit_is_enforced_atomically_on_first_step_submission(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $edition->forceFill(['max_entries_per_participant' => 1])->save();
        $this->requirement($edition, $workflow, 'application', 'selection-video', 'url');

        $first = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'First entry'));
        $firstStep = $first->steps->firstWhere('code', 'application');
        app(StoreFestivalResponse::class)->execute($firstStep->requirements->first(), $portalUser, 'https://video.example/one');
        app(SubmitFestivalEntryStep::class)->execute($first, $firstStep);

        $second = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'Second entry'));
        $secondStep = $second->steps->firstWhere('code', 'application');
        app(StoreFestivalResponse::class)->execute($secondStep->requirements->first(), $portalUser, 'https://video.example/two');

        try {
            app(SubmitFestivalEntryStep::class)->execute($second, $secondStep);
            $this->fail('The per-participant limit must reject a second active entry.');
        } catch (ValidationException) {
            $this->assertSame(FestivalEntryStatus::Draft, $second->refresh()->status);
            $this->assertNull($second->submitted_at);
            $this->assertSame(FestivalEntryStepStatus::Draft, $secondStep->refresh()->status);
        }
    }

    public function test_lowering_a_paid_priced_answer_creates_a_non_blocking_refund_adjustment(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $this->requirement($edition, $workflow, 'technical_form', 'helpers', 'integer', ['mode' => 'per_unit', 'unit_amount_cents' => 1000]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'Priced entry'));
        $entry->steps()->whereIn('code', ['application', 'participation_payment'])->update(['status' => FestivalEntryStepStatus::Approved->value]);
        $entry->load('steps.requirements');
        $technical = $entry->steps->firstWhere('code', 'technical_form');
        $requirement = $technical->requirements->first();

        $first = app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 3);
        $paidCharge = $entry->charges()->where('festival_submission_id', $first->id)->firstOrFail();
        $paidCharge->forceFill(['status' => FestivalChargeStatus::Paid, 'paid_at' => now()])->save();
        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 1);

        $this->assertSame(3000, $paidCharge->refresh()->amount_cents);
        $this->assertDatabaseHas('festival_charge_adjustments', [
            'festival_entry_id' => $entry->id,
            'direction' => 'refund',
            'status' => 'pending',
            'amount_cents' => 2000,
        ]);
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser, FestivalParticipant, FestivalCategory, FestivalWorkflow} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id, 'age_reference_date' => now()->addMonth()->toDateString()]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'QA registration');
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'festival_workflow_id' => $workflow->id]);

        return [$account, $edition, $portalUser, $participant, $category, $workflow];
    }

    /** @param array<string, mixed> $pricing */
    private function requirement(FestivalEdition $edition, FestivalWorkflow $workflow, string $stepCode, string $code, string $inputType, array $pricing = ['mode' => 'none']): FestivalRequirementDefinition
    {
        return FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $edition->account_id,
            'festival_workflow_step_id' => $workflow->steps->firstWhere('code', $stepCode)->id,
            'code' => $code,
            'type' => 'custom_document',
            'subject_scope' => 'entry',
            'input_type' => $inputType,
            'pricing' => $pricing,
            'is_required' => true,
            'is_active' => true,
        ]);
    }

    private function entry(Account $account, FestivalEdition $edition, FestivalPortalUser $portalUser, FestivalParticipant $participant, FestivalCategory $category, string $name): FestivalEntry
    {
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => $name,
        ]);
        $entry->participants()->sync([$participant->id => [
            'account_id' => $account->id,
            'sort_order' => 0,
            'age_snapshot' => $participant->date_of_birth->diffInYears($edition->age_reference_date),
            'name_snapshot' => $participant->displayName(),
        ]]);

        return $entry;
    }
}
