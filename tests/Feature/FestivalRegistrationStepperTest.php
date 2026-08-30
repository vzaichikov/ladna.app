<?php

namespace Tests\Feature;

use App\Actions\Festivals\DeleteFestivalEntry;
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
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalPaymentStatus;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalRequirementType;
use App\Enums\FestivalTeamMemberType;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\StudioPermission;
use App\Http\Middleware\PreventExpiredSubscriptionMutations;
use App\Models\Account;
use App\Models\FestivalActivityLog;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalNotification;
use App\Models\FestivalParticipant;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\FestivalWorkflow;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Festivals\FestivalRequirementDeadlineResolver;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class FestivalRegistrationStepperTest extends TestCase
{
    use DatabaseTransactions;

    public function test_media_file_cards_show_the_effective_category_duration(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $category->forceFill([
            'min_duration_seconds' => 150,
            'max_duration_seconds' => 195,
        ])->save();

        $music = $this->requirement($edition, $workflow, 'application', 'performance-music', 'file');
        $music->forceFill(['type' => FestivalRequirementType::Music])->save();
        $video = $this->requirement($edition, $workflow, 'application', 'backdrop-video', 'file');
        $video->forceFill(['type' => FestivalRequirementType::Backdrop])->save();
        $this->requirement($edition, $workflow, 'application', 'unrestricted-document', 'file');

        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Media duration entry'),
        );
        $step = $this->step($entry, 'application');
        $page = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]));

        $page->assertOk();
        $durationLabel = __('app.festival_requirement_duration_label_range', [
            'min' => '2:30',
            'max' => '3:15',
        ]);
        $this->assertSame(2, substr_count($page->getContent(), e($durationLabel)));
    }

    public function test_response_component_formats_current_typed_values_safely(): void
    {
        $render = fn (string $inputType, mixed $value, array $options = []): string => Blade::render(
            '<x-festivals.response-value :definition="$definition" :value="$value" />',
            ['definition' => new FestivalRequirementDefinition(['input_type' => $inputType, 'options' => $options]), 'value' => $value],
        );

        $this->assertStringContainsString('Short answer', $render('short_text', 'Short answer'));
        $this->assertStringContainsString("First line\nSecond line", $render('long_text', "First line\nSecond line"));
        $this->assertStringContainsString('12', $render('integer', 12));
        $this->assertStringContainsString(__('app.yes'), $render('boolean', true));
        $this->assertStringContainsString(__('app.no'), $render('boolean', false));
        $this->assertStringContainsString(__('app.yes'), $render('agreement', true));
        $this->assertStringContainsString(__('app.no'), $render('agreement', false));
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

        $helperDefinition = new FestivalRequirementDefinition(['input_type' => 'helper_selection']);
        $helpers = collect([
            new FestivalParticipant(['first_name' => 'One', 'last_name' => 'Helper']),
            new FestivalParticipant(['first_name' => 'Two', 'last_name' => 'Helper']),
        ]);
        $helperSummary = Blade::render(
            '<x-festivals.response-value :definition="$definition" :value="$value" :helpers="$helpers" />',
            ['definition' => $helperDefinition, 'value' => ['enabled' => true], 'helpers' => $helpers],
        );
        $this->assertStringContainsString(__('app.festival_selected_helpers_summary', [
            'count' => 2,
            'names' => 'Helper One, Helper Two',
        ]), $helperSummary);
    }

    public function test_yes_no_fields_use_ajax_radios_and_save_an_explicit_no_value(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $definition = $this->requirement($edition, $workflow, 'application', 'yes-or-no', 'boolean');
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Yes or no entry'),
        );
        $step = $this->step($entry, 'application');
        $requirement = $step->requirements->firstWhere('festival_requirement_definition_id', $definition->id);
        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, true);
        $requirement->forceFill([
            'status' => FestivalRequirementStatus::Rejected,
            'review_notes' => 'Replace this answer <script>alert("unsafe")</script>',
        ])->save();

        $page = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]));

        $page->assertOk()
            ->assertSee('data-festival-requirement-card', false)
            ->assertSee('data-async-form', false)
            ->assertSee('type="radio"', false)
            ->assertSee('name="value"', false)
            ->assertSee('value="1"', false)
            ->assertSee('value="0"', false)
            ->assertSee('data-async-submit-on-change', false)
            ->assertSee('type="submit"', false)
            ->assertDontSee('type="checkbox"', false)
            ->assertSee('crm-status-danger', false)
            ->assertSee('border-rose-300 bg-rose-50', false)
            ->assertSee('Replace this answer &lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("unsafe")</script>', false);

        $this->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $requirement]), [
            'value' => 'not-a-boolean',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $saved = $this->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $requirement]), [
            'value' => false,
        ]);
        $saved->assertOk()
            ->assertJsonPath('requirement_id', $requirement->id)
            ->assertJsonPath('message', __('app.festival_response_saved'));
        $this->assertStringContainsString('data-festival-requirement-card', $saved->json('requirement_html'));
        $this->assertStringContainsString('crm-status-warning', $saved->json('requirement_html'));
        $this->assertStringNotContainsString('Replace this answer', $saved->json('requirement_html'));
        $this->assertSame(FestivalRequirementStatus::Submitted, $requirement->refresh()->status);
        $this->assertNull($requirement->review_notes);
        $this->assertFalse(data_get($requirement->submissions()->firstOrFail()->value_json, 'value'));

        $this->post(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $requirement]), [
            'value' => '0',
        ])->assertRedirect()->assertSessionHas('status', __('app.festival_response_saved'));
    }

    public function test_agreement_fields_use_ajax_checkboxes_and_require_confirmation(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $definition = $this->requirement($edition, $workflow, 'application', 'agreement', 'agreement');
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Agreement entry'),
        );
        $step = $this->step($entry, 'application');
        $requirement = $step->requirements->firstWhere('festival_requirement_definition_id', $definition->id);

        $definition->update(['input_type' => 'boolean']);
        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, false);
        $this->assertTrue($requirement->unsetRelation('definition')->unsetRelation('submissions')->hasSubmittedResponse());
        $definition->update(['input_type' => 'agreement']);
        $this->assertFalse($requirement->unsetRelation('definition')->unsetRelation('submissions')->hasSubmittedResponse());

        $page = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]));

        $page->assertOk()
            ->assertSee('type="checkbox"', false)
            ->assertSee('name="value"', false)
            ->assertSee('value="1"', false)
            ->assertSee('data-async-submit-on-change', false)
            ->assertSee(__('app.festival_agreement_confirm'))
            ->assertSee('type="submit"', false)
            ->assertDontSee('type="radio"', false);
        $this->assertDoesNotMatchRegularExpression(
            '/<input(?=[^>]*type="checkbox")(?=[^>]*name="value")(?=[^>]*required)[^>]*>/s',
            $page->getContent(),
        );

        $this->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $requirement]), [
            'value' => 'not-a-boolean',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $revoked = $this->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $requirement]), [
            'value' => false,
        ]);
        $revoked->assertOk()
            ->assertJsonPath('requirement_id', $requirement->id)
            ->assertJsonPath('message', __('app.festival_response_saved'));
        $this->assertStringContainsString('data-festival-requirement-complete="false"', $revoked->json('requirement_html'));
        $this->assertSame(FestivalRequirementStatus::Missing, $requirement->refresh()->status);
        $this->assertFalse(data_get($requirement->submissions()->firstOrFail()->value_json, 'value'));
        $this->assertFalse($requirement->unsetRelation('definition')->unsetRelation('submissions')->hasSubmittedResponse());

        $saved = $this->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $requirement]), [
            'value' => true,
        ]);

        $saved->assertOk()
            ->assertJsonPath('requirement_id', $requirement->id)
            ->assertJsonPath('message', __('app.festival_response_saved'));
        $this->assertStringContainsString('data-festival-requirement-complete="true"', $saved->json('requirement_html'));
        $this->assertSame(FestivalRequirementStatus::Submitted, $requirement->refresh()->status);
        $this->assertTrue(data_get($requirement->submissions()->firstOrFail()->value_json, 'value'));

        $revokedAgain = $this->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $requirement]), [
            'value' => '0',
        ]);
        $revokedAgain->assertOk();
        $this->assertStringContainsString('data-festival-requirement-complete="false"', $revokedAgain->json('requirement_html'));
        $this->assertSame(FestivalRequirementStatus::Missing, $requirement->refresh()->status);
        $this->assertFalse(data_get($requirement->submissions()->firstOrFail()->value_json, 'value'));

        $this->post(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $requirement]), [
            'value' => '1',
        ])->assertRedirect()->assertSessionHas('status', __('app.festival_response_saved'));
    }

    public function test_required_fields_and_every_condition_confirmation_gate_submission_and_payment(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $textDefinition = $this->requirement($edition, $workflow, 'application', 'required-name', 'short_text');
        $agreementDefinition = $this->requirement($edition, $workflow, 'application', 'conditions', 'agreement');
        $agreementDefinition->forceFill(['is_required' => false])->save();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Gated entry'),
        );
        $step = $this->step($entry, 'application');
        $textRequirement = $step->requirements->firstWhere('festival_requirement_definition_id', $textDefinition->id);
        $agreementRequirement = $step->requirements->firstWhere('festival_requirement_definition_id', $agreementDefinition->id);
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $step->id,
            'code' => 'FCH-GATED-'.$entry->id,
            'kind' => 'qualification',
            'name' => 'Gated fee',
            'amount_cents' => 10000,
            'currency' => 'UAH',
        ]);
        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Liqpay,
            'category' => IntegrationCategory::Payment,
            'is_enabled' => true,
            'credentials' => ['public_key' => 'studio-public', 'private_key' => 'studio-private'],
        ]);

        $page = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]));
        $page->assertOk()
            ->assertSee('data-festival-requirement-blocking="true"', false)
            ->assertSee('data-festival-requirement-complete="false"', false)
            ->assertSee('data-festival-progress-action', false)
            ->assertSee('disabled', false)
            ->assertSee(__('app.festival_complete_required_fields_first'));

        try {
            app(SubmitFestivalEntryStep::class)->execute($entry, $step);
            $this->fail('The step must remain blocked while required fields and confirmations are incomplete.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('step', $exception->errors());
        }

        $agreementRequirement->forceFill(['status' => FestivalRequirementStatus::Waived])->save();
        $this->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), [
            'provider' => IntegrationProvider::Liqpay->value,
            'festival_rules_accepted' => '1',
        ])->assertRedirect()->assertSessionHasErrorsIn('festival_payment_'.$charge->id, 'provider');

        app(StoreFestivalResponse::class)->execute($textRequirement, $portalUser, 'Ready');
        $agreementRequirement->forceFill(['status' => FestivalRequirementStatus::Missing])->save();
        app(StoreFestivalResponse::class)->execute($agreementRequirement, $portalUser, false);
        $this->assertSame(FestivalRequirementStatus::Missing, $agreementRequirement->refresh()->status);
        $this->assertFalse($agreementRequirement->unsetRelation('definition')->unsetRelation('submissions')->hasSubmittedResponse());

        try {
            app(SubmitFestivalEntryStep::class)->execute($entry, $step);
            $this->fail('The step must remain blocked after a condition confirmation is revoked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('step', $exception->errors());
        }

        $this->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), [
            'provider' => IntegrationProvider::Liqpay->value,
            'festival_rules_accepted' => '1',
        ])->assertRedirect()->assertSessionHasErrorsIn('festival_payment_'.$charge->id, 'provider');

        app(StoreFestivalResponse::class)->execute($agreementRequirement, $portalUser, true);

        $readyPage = $this->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]));
        $readyPage->assertOk()
            ->assertSee(__('app.festival_submit_and_pay'))
            ->assertDontSee('data-festival-progress-action="data-festival-progress-action" disabled', false);

        $this->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), [
            'provider' => IntegrationProvider::Liqpay->value,
            'festival_rules_accepted' => '1',
        ])->assertOk()->assertSee('https://www.liqpay.ua/api/3/checkout', false);
    }

    public function test_accepted_application_allows_only_configured_fields_until_the_relative_deadline_and_returns_changes_for_approval(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $category->forceFill(['maximum_accepted_entries' => 1])->save();
        $editableDefinition = $this->requirement($edition, $workflow, 'application', 'editable-note', 'short_text');
        $editableDefinition->forceFill(['validation' => [
            'allowed_hosts' => [],
            'allow_post_confirmation_edits' => true,
            'editable_until_rule' => ['reference' => 'starts_at', 'offset_days' => 10],
        ]])->save();
        $lockedDefinition = $this->requirement($edition, $workflow, 'application', 'locked-note', 'short_text');
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Editable accepted entry'),
        );
        $step = $this->step($entry, 'application');
        $editableRequirement = $step->requirements->firstWhere('festival_requirement_definition_id', $editableDefinition->id);
        $lockedRequirement = $step->requirements->firstWhere('festival_requirement_definition_id', $lockedDefinition->id);
        app(StoreFestivalResponse::class)->execute($editableRequirement, $portalUser, 'Original editable value');
        app(StoreFestivalResponse::class)->execute($lockedRequirement, $portalUser, 'Original locked value');
        $entry->requirements()->update(['status' => FestivalRequirementStatus::Accepted->value]);
        $entry->steps()->update(['status' => FestivalEntryStepStatus::Approved->value, 'reviewed_at' => now()]);
        $acceptedAt = now()->subDay()->startOfSecond();
        $completedAt = now()->subHours(23)->startOfSecond();
        $entry->forceFill([
            'status' => FestivalEntryStatus::Accepted,
            'accepted_at' => $acceptedAt,
            'registration_completed_at' => $completedAt,
        ])->save();

        $page = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entries.show', [$account->slug, $entry]));
        $page->assertOk()
            ->assertSee(__('app.festival_summary_accepted_title'))
            ->assertSee(__('app.festival_field_editable_until', [
                'date' => app(FestivalRequirementDeadlineResolver::class)
                    ->editableUntil($editableDefinition)
                    ->timezone($edition->timezone)
                    ->format('d.m.Y H:i'),
            ]))
            ->assertSee(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $editableRequirement]), false)
            ->assertDontSee(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $lockedRequirement]), false);

        $saved = $this->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $editableRequirement]), [
            'value' => 'Applicant changed this',
        ]);
        $saved->assertOk()
            ->assertJsonPath('requirement_id', $editableRequirement->id)
            ->assertJsonPath('reload', true);
        $this->assertSame(FestivalEntryStatus::ChangesPending, $entry->refresh()->status);
        $this->assertSame(FestivalEntryStepStatus::Submitted, $step->refresh()->status);
        $this->assertSame(FestivalRequirementStatus::Submitted, $editableRequirement->refresh()->status);
        $this->assertSame(1, FestivalNotification::query()
            ->where('festival_entry_id', $entry->id)
            ->where('type', FestivalNotificationType::EntrySubmitted->value)
            ->count());

        $this->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $editableRequirement]), [
            'value' => 'Applicant changed this again',
        ])->assertOk()->assertJsonPath('reload', false);
        $this->assertSame(1, FestivalNotification::query()
            ->where('festival_entry_id', $entry->id)
            ->where('type', FestivalNotificationType::EntrySubmitted->value)
            ->count());

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->actingAs($owner, 'web')
            ->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_entry_status_changes_pending'));

        app(ReviewFestivalEntryStep::class)->execute($step->refresh(), $owner, 'approve');
        $this->assertSame(FestivalEntryStatus::ChangesPending, $entry->refresh()->status);
        $this->assertSame(FestivalEntryStepStatus::Approved, $step->refresh()->status);
        $this->actingAs($owner, 'web')
            ->patch(route('dashboard.accounts.festivals.applications.fully-confirm', [$account, $edition, $entry]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(FestivalEntryStatus::Accepted, $entry->refresh()->status);
        $this->assertTrue($entry->accepted_at->equalTo($acceptedAt));
        $this->assertTrue($entry->registration_completed_at->equalTo($completedAt));

        $editableDefinition->forceFill(['validation' => [
            'allowed_hosts' => [],
            'allow_post_confirmation_edits' => true,
            'editable_until_rule' => ['reference' => 'starts_at', 'offset_days' => -366],
        ]])->save();
        $this->actingAs($portalUser, 'festival')
            ->postJson(route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $step, $editableRequirement]), [
                'value' => 'Too late',
            ])
            ->assertStatus(409);
    }

    public function test_required_files_upload_asynchronously_and_cannot_be_accepted_or_advance_without_a_submission(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $linkDefinition = $this->requirement($edition, $workflow, 'application', 'selection-link', 'url');
        $fileDefinition = $this->requirement($edition, $workflow, 'application', 'performance-music', 'file');
        $fileDefinition->update([
            'name' => 'Performance music',
            'allowed_extensions' => ['mp3'],
            'allowed_mime_types' => [],
        ]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'File upload entry'),
        );
        $step = $this->step($entry, 'application');
        $linkRequirement = $step->requirements->firstWhere('festival_requirement_definition_id', $linkDefinition->id);
        $fileRequirement = $step->requirements->firstWhere('festival_requirement_definition_id', $fileDefinition->id);
        app(StoreFestivalResponse::class)->execute($linkRequirement, $portalUser, 'https://music.example/preview');

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.festivals.requirements.review', [$account, $edition, $fileRequirement]), [
                'status' => FestivalRequirementStatus::Accepted->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertSame(FestivalRequirementStatus::Missing, $fileRequirement->refresh()->status);

        $fileRequirement->forceFill(['status' => FestivalRequirementStatus::Accepted])->save();
        try {
            app(SubmitFestivalEntryStep::class)->execute($entry->refresh(), $step->refresh());
            $this->fail('An accepted file requirement without a submission must not advance its step.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('step', $exception->errors());
        }
        $this->assertSame(FestivalEntryStepStatus::Draft, $step->refresh()->status);

        $step->forceFill(['status' => FestivalEntryStepStatus::Submitted])->save();
        try {
            app(ReviewFestivalEntryStep::class)->execute($step->refresh(), $owner, 'approve');
            $this->fail('A step with an accepted file requirement but no submission must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }
        $step->forceFill(['status' => FestivalEntryStepStatus::Draft])->save();
        $fileRequirement->forceFill(['status' => FestivalRequirementStatus::Missing])->save();

        $portalPage = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]));
        $portalPage->assertOk()
            ->assertSee(route('festival.portal.submissions.store', [$account->slug, $entry, $fileRequirement]), false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('data-async-form', false)
            ->assertSee('data-async-error-for="file"', false);

        $jsonHeaders = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
        $uploadUrl = route('festival.portal.submissions.store', [$account->slug, $entry, $fileRequirement]);
        $this->withHeaders($jsonHeaders)
            ->post($uploadUrl)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $uploaded = $this->withHeaders($jsonHeaders)->post($uploadUrl, [
            'file' => UploadedFile::fake()->create('performance.mp3', 12, 'audio/mpeg'),
        ]);
        $uploaded->assertOk()
            ->assertJsonPath('requirement_id', $fileRequirement->id)
            ->assertJsonPath('message', __('app.festival_submission_saved'));
        $this->assertStringContainsString('data-festival-requirement-card', $uploaded->json('requirement_html'));
        $this->assertStringContainsString('crm-status-warning', $uploaded->json('requirement_html'));
        $submission = $fileRequirement->submissions()->firstOrFail();
        Storage::disk('local')->assertExists($submission->path);

        $this->flushHeaders()->post($uploadUrl, [
            'file' => UploadedFile::fake()->create('replacement.mp3', 14, 'audio/mpeg'),
        ])->assertRedirect()->assertSessionHas('status', __('app.festival_submission_saved'));
        $submission->refresh();
        $this->assertSame('replacement.mp3', $submission->original_name);

        $application = $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]));
        $application->assertOk()
            ->assertSeeInOrder([
                __('app.festival_payments'),
                __('app.files'),
                'Performance music',
                __('app.festival_checklist'),
            ])
            ->assertSee(route('dashboard.accounts.festivals.submissions.view', [$account, $submission]), false)
            ->assertSee(route('dashboard.accounts.festivals.submissions.download', [$account, $submission]), false)
            ->assertSee('target="_blank"', false)
            ->assertSee('https://music.example/preview');

        $viewResponse = $this->get(route('dashboard.accounts.festivals.submissions.view', [$account, $submission]));
        $viewResponse->assertOk()->assertHeader('accept-ranges', 'bytes');
        $this->assertInstanceOf(BinaryFileResponse::class, $viewResponse->baseResponse);
        $this->assertStringStartsWith('inline;', (string) $viewResponse->headers->get('content-disposition'));
        $downloadResponse = $this->get(route('dashboard.accounts.festivals.submissions.download', [$account, $submission]));
        $downloadResponse->assertOk()->assertStreamed();
        $this->assertStringStartsWith('attachment;', (string) $downloadResponse->headers->get('content-disposition'));

        $judge = User::factory()->create();
        $account->users()->attach($judge->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::JudgeFestivals->value],
        ]);
        $viewUrl = route('dashboard.accounts.festivals.submissions.view', [$account, $submission]);
        $downloadUrl = route('dashboard.accounts.festivals.submissions.download', [$account, $submission]);
        $this->actingAs($judge)->get($viewUrl)->assertForbidden();
        $this->actingAs($judge)->get($downloadUrl)->assertForbidden();

        $otherCategory = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($judge)->create(['account_id' => $account->id]);
        $assignment->categories()->attach($otherCategory->id, ['account_id' => $account->id]);
        $this->actingAs($judge)->get($viewUrl)->assertForbidden();
        $this->actingAs($judge)->get($downloadUrl)->assertForbidden();

        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $assignedJudgeView = $this->actingAs($judge)->get($viewUrl);
        $this->assertSame(200, $assignedJudgeView->getStatusCode(), 'An active judge assigned to the entry category must be able to preview its submission.');
        $assignedJudgeDownload = $this->actingAs($judge)->get($downloadUrl);
        $this->assertSame(200, $assignedJudgeDownload->getStatusCode(), 'An active judge assigned to the entry category must be able to download its submission.');

        $assignment->update(['is_active' => false]);
        $this->actingAs($judge)->get($viewUrl)->assertForbidden();
        $this->actingAs($judge)->get($downloadUrl)->assertForbidden();

        $fileDefinition->update(['allowed_extensions' => [], 'allowed_mime_types' => []]);
        $this->actingAs($portalUser, 'festival')->post($uploadUrl, [
            'file' => UploadedFile::fake()->createWithContent('active.html', '<!doctype html><script>document.cookie</script>'),
        ])->assertRedirect()->assertSessionHas('status', __('app.festival_submission_saved'));
        $submission->refresh();
        $this->assertSame('text/html', $submission->mime_type);

        $unsafeView = $this->actingAs($owner, 'web')->get(route('dashboard.accounts.festivals.submissions.view', [$account, $submission]));
        $unsafeView->assertOk()->assertDownload('active.html');
        $this->assertSame('application/octet-stream', $unsafeView->headers->get('content-type'));

        app(SubmitFestivalEntryStep::class)->execute($entry->refresh(), $step->refresh());
        $this->assertSame(FestivalEntryStepStatus::Submitted, $step->refresh()->status);
    }

    public function test_staff_application_review_forms_save_asynchronously_with_semantic_field_statuses(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $portalUser->update([
            'first_name' => 'Async',
            'last_name' => 'Applicant',
            'phone' => '+380991112233',
            'email' => 'async.applicant@example.test',
            'telegram_contact' => '@async_applicant',
            'instagram_url' => '@async.applicant',
        ]);
        $category->update(['requirements_html' => '<p>Category requirements sentinel.</p>']);
        $definition = $this->requirement($edition, $workflow, 'application', 'selection-video', 'url');
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Async review entry'),
        );
        $step = $this->step($entry, 'application');
        $requirement = $step->requirements->firstWhere('festival_requirement_definition_id', $definition->id);
        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 'https://video.example/async-review');
        app(SubmitFestivalEntryStep::class)->execute($entry, $step);
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $step->id,
            'code' => 'FCH-ASYNC-'.$entry->id,
            'kind' => 'custom',
            'name' => 'Manual review charge',
            'status' => FestivalChargeStatus::Pending,
            'amount_cents' => 2500,
            'currency' => 'UAH',
            'due_at' => now()->addDay(),
        ]);
        $targetCategory = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
            'name' => 'Async target category',
        ]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $applicationUrl = route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]);
        $page = $this->actingAs($owner)->get($applicationUrl);
        $page->assertOk()
            ->assertSee('data-festival-applicant-contacts', false)
            ->assertSee('href="'.route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser]).'" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('href="tel:+380991112233" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('href="mailto:async.applicant@example.test" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('href="https://t.me/async_applicant" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('href="https://instagram.com/async.applicant" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('data-festival-application-fragment-key="category-'.$entry->id.'"', false)
            ->assertSee($targetCategory->name)
            ->assertSee('aria-controls="festival-category-requirements-modal-'.$entry->id.'"', false)
            ->assertSee('data-festival-category-requirements-modal', false)
            ->assertSee('Category requirements sentinel.')
            ->assertSee('data-festival-category-modal-open', false)
            ->assertSee('id="festival-category-modal-'.$entry->id.'"', false)
            ->assertSee('data-festival-category-modal', false)
            ->assertSee('class="fixed inset-0 z-50 hidden', false)
            ->assertSee('name="category_reassignment_form" value="1"', false)
            ->assertSee('maxlength="2000"', false)
            ->assertSee('data-festival-application-fragment-key="step-'.$entry->id.'"', false)
            ->assertSee('data-festival-application-fragment-key="requirement-'.$requirement->id.'"', false)
            ->assertSee('data-festival-application-fragment-key="charge-'.$charge->id.'"', false)
            ->assertSee('data-async-form', false)
            ->assertSee('crm-status-warning', false)
            ->assertSee(__('app.festival_requirement_status_submitted'));

        $categoryReview = $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.festivals.entries.reassign-category', [$account, $edition, $entry]), [
                'festival_category_id' => $targetCategory->id,
                'reason' => 'Better category fit.',
            ]);
        $categoryReview->assertOk()
            ->assertJsonPath('message', __('app.festival_category_reassigned'))
            ->assertJsonCount(2, 'fragments_html');
        $this->assertStringContainsString('data-festival-application-fragment-key="category-'.$entry->id.'"', $categoryReview->json('fragments_html.0'));
        $this->assertStringContainsString($targetCategory->name, $categoryReview->json('fragments_html.0'));
        $this->assertStringContainsString('data-festival-category-modal-open', $categoryReview->json('fragments_html.0'));
        $this->assertStringContainsString('data-festival-category-modal', $categoryReview->json('fragments_html.0'));
        $this->assertSame($targetCategory->id, $entry->refresh()->festival_category_id);

        $this->actingAs($owner)
            ->from($applicationUrl)
            ->patch(route('dashboard.accounts.festivals.entries.reassign-category', [$account, $edition, $entry]), [
                'category_reassignment_form' => '1',
                'festival_category_id' => $category->id,
            ])
            ->assertRedirect($applicationUrl)
            ->assertSessionHasErrors('reason')
            ->assertSessionHasInput('category_reassignment_form', '1');

        $this->get($applicationUrl)
            ->assertOk()
            ->assertSee('data-festival-category-modal', false)
            ->assertSee('data-open="true"', false)
            ->assertSee('value="'.$category->id.'" selected', false);

        $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.festivals.requirements.review', [$account, $edition, $requirement]), [
                'status' => 'unknown',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $accepted = $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.festivals.requirements.review', [$account, $edition, $requirement]), [
                'status' => FestivalRequirementStatus::Accepted->value,
                'review_notes' => 'Accepted asynchronously.',
            ]);
        $accepted->assertOk()
            ->assertJsonPath('message', __('app.festival_requirement_reviewed'));
        $this->assertStringContainsString('data-festival-application-fragment-key="requirement-'.$requirement->id.'"', $accepted->json('fragment_html'));
        $this->assertStringContainsString('crm-status-active', $accepted->json('fragment_html'));
        $this->assertSame(FestivalRequirementStatus::Accepted, $requirement->refresh()->status);
        $acceptedNotification = FestivalNotification::query()
            ->where('festival_entry_id', $entry->id)
            ->where('type', FestivalNotificationType::RequirementAccepted->value)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(FestivalRequirementStatus::Accepted->value, $acceptedNotification->payload['status']);
        $this->assertStringNotContainsString('Decision: accepted', (string) $acceptedNotification->text);

        $rejected = $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.festivals.requirements.review', [$account, $edition, $requirement]), [
                'status' => FestivalRequirementStatus::Rejected->value,
                'review_notes' => 'Rejected asynchronously.',
            ]);
        $rejected->assertOk();
        $this->assertStringContainsString('crm-status-danger', $rejected->json('fragment_html'));
        $this->assertSame(FestivalRequirementStatus::Rejected, $requirement->refresh()->status);
        $rejectedNotification = FestivalNotification::query()
            ->where('festival_entry_id', $entry->id)
            ->where('type', FestivalNotificationType::RequirementRejected->value)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(FestivalRequirementStatus::Rejected->value, $rejectedNotification->payload['status']);
        $this->assertStringNotContainsString('Decision: rejected', (string) $rejectedNotification->text);

        $waived = $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.festivals.requirements.review', [$account, $edition, $requirement]), [
                'status' => FestivalRequirementStatus::Waived->value,
                'review_notes' => 'Waived asynchronously.',
            ]);
        $waived->assertOk();
        $this->assertSame(FestivalRequirementStatus::Waived, $requirement->refresh()->status);
        $waivedNotification = FestivalNotification::query()
            ->where('festival_entry_id', $entry->id)
            ->where('type', FestivalNotificationType::RequirementWaived->value)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(FestivalRequirementStatus::Waived->value, $waivedNotification->payload['status']);

        $this->actingAs($owner)
            ->from($applicationUrl)
            ->patch(route('dashboard.accounts.festivals.requirements.review', [$account, $edition, $requirement]), [
                'status' => FestivalRequirementStatus::Accepted->value,
            ])
            ->assertRedirect($applicationUrl);

        $chargeReview = $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.festivals.charges.manual-review', [$account, $edition, $charge]), [
                'decision' => 'approve',
                'notes' => 'Received.',
            ]);
        $chargeReview->assertOk()
            ->assertJsonPath('message', __('app.festival_charge_reviewed'));
        $this->assertStringContainsString('data-festival-application-fragment-key="charge-'.$charge->id.'"', $chargeReview->json('fragment_html'));
        $this->assertSame(FestivalChargeStatus::Paid, $charge->refresh()->status);
        $this->assertSame(1, FestivalNotification::query()
            ->where('festival_entry_id', $entry->id)
            ->where('type', FestivalNotificationType::PaymentPaid->value)
            ->count());

        $stepReview = $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.festivals.entry-steps.review', [$account, $edition, $entry, $step]), [
                'decision' => 'approve',
                'comment' => 'Qualified.',
            ]);
        $stepReview->assertOk()
            ->assertJsonPath('message', __('app.festival_step_reviewed'))
            ->assertJsonCount(2, 'fragments_html');
        $this->assertStringContainsString('data-festival-application-fragment-key="step-'.$entry->id.'"', $stepReview->json('fragments_html.0'));
        $this->assertStringContainsString('data-festival-application-fragment-key="charges-'.$entry->id.'"', $stepReview->json('fragments_html.1'));
        $this->assertSame(FestivalEntryStepStatus::Approved, $step->refresh()->status);

        $laterStep = $entry->steps()->where('id', '!=', $step->id)->orderByDesc('id')->firstOrFail();
        $laterStep->forceFill(['status' => FestivalEntryStepStatus::Submitted])->save();
        $laterCategoryReview = $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.festivals.entries.reassign-category', [$account, $edition, $entry]), [
                'festival_category_id' => $category->id,
                'reason' => 'Return from a later registration step.',
            ]);
        $laterCategoryReview->assertOk();
        $this->assertSame($category->id, $entry->refresh()->festival_category_id);
    }

    public function test_entry_charge_uses_only_the_studio_payment_account_and_renders_failed_and_paid_states(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category] = $this->festival();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Payment entry'),
        );
        $step = $this->step($entry, 'application');
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $step->id,
            'code' => 'FCH-PORTAL-'.$entry->id,
            'kind' => 'qualification',
            'name' => 'Qualification fee',
            'amount_cents' => 50000,
            'currency' => 'UAH',
        ]);
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::Liqpay,
            'category' => IntegrationCategory::Payment,
            'is_enabled' => true,
            'credentials' => ['public_key' => 'platform-public', 'private_key' => 'platform-private'],
        ]);

        $page = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]));
        $page->assertOk()
            ->assertSee('data-festival-charge-card', false)
            ->assertSee(__('app.no_payment_methods_available'))
            ->assertDontSee('name="provider"', false);

        $paymentError = $this->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), [
            'provider' => IntegrationProvider::Liqpay->value,
            'festival_rules_accepted' => '1',
        ]);
        $paymentError
            ->assertRedirect(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]).'#festival-charge-'.$charge->id)
            ->assertSessionHasErrorsIn('festival_payment_'.$charge->id, 'provider');
        $this->assertFalse(session('errors')->getBag('default')->any());

        $this->followingRedirects()
            ->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), [
                'provider' => IntegrationProvider::Liqpay->value,
                'festival_rules_accepted' => '1',
            ])
            ->assertOk()
            ->assertSeeInOrder(['data-festival-charge-card', 'data-festival-payment-error'], false);

        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Liqpay,
            'category' => IntegrationCategory::Payment,
            'is_enabled' => true,
            'credentials' => ['public_key' => 'studio-public', 'private_key' => 'studio-private'],
        ]);
        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Monopay,
            'category' => IntegrationCategory::Payment,
            'is_enabled' => true,
            'credentials' => ['api_token' => 'studio-mono-token'],
        ]);

        $configuredPage = $this->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]));
        $configuredPage->assertOk()
            ->assertSee('name="provider" value="liqpay"', false)
            ->assertSee('lg:grid-cols-[1fr_0.75fr]', false)
            ->assertSee(__('app.festival_payment_for'))
            ->assertSee('name="festival_rules_accepted"', false)
            ->assertSee(__('app.festival_rules_agreement_prefix'))
            ->assertSee(__('app.festival_rules_link_text'))
            ->assertSee('bg-emerald-600 text-white', false)
            ->assertSee(__('app.festival_submit_and_pay'))
            ->assertDontSee(route('festival.portal.entry-steps.submit', [$account->slug, $entry, $step]), false)
            ->assertSee('alt="Google Pay"', false)
            ->assertSee('alt="Apple Pay"', false)
            ->assertSee('alt="Visa"', false)
            ->assertSee('alt="Mastercard"', false)
            ->assertDontSee('<select name="provider"', false)
            ->assertDontSee(__('app.no_payment_methods_available'));

        $rulesError = $this->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), [
            'provider' => IntegrationProvider::Liqpay->value,
        ]);
        $rulesError->assertSessionHasErrorsIn('festival_payment_'.$charge->id, 'festival_rules_accepted');
        $this->followingRedirects()
            ->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), [
                'provider' => IntegrationProvider::Liqpay->value,
            ])
            ->assertOk()
            ->assertSee('data-festival-payment-error', false)
            ->assertSee(__('app.festival_rules_accepted'));

        $this->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), [
            'provider' => IntegrationProvider::Liqpay->value,
            'festival_rules_accepted' => '1',
        ])->assertOk()->assertSee('https://www.liqpay.ua/api/3/checkout', false);
        $firstAttempt = FestivalPaymentAttempt::query()->where('festival_charge_id', $charge->id)->sole();
        $this->assertSame($account->id, $firstAttempt->account_id);
        $paymentStarted = FestivalActivityLog::query()->where('subject_type', $firstAttempt->getMorphClass())->where('subject_id', $firstAttempt->id)->where('action', 'payment.started')->sole();
        $this->assertSame($entry->id, $paymentStarted->festival_entry_id);
        $this->assertSame($portalUser->id, $paymentStarted->actor_portal_user_id);
        $this->assertArrayNotHasKey('gateway_checkout_payload', $paymentStarted->payload);

        app(FestivalPaymentService::class)->completeAttempt($firstAttempt, new PaymentCallbackResult(
            orderId: $firstAttempt->order_id,
            status: PaymentCallbackStatus::Failed,
            amountCents: $firstAttempt->amount_cents,
            currency: $firstAttempt->currency,
        ));
        $this->assertSame(FestivalPaymentStatus::Failed, $firstAttempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::Failed, $charge->refresh()->status);
        $failedActivity = FestivalActivityLog::query()->where('subject_type', $firstAttempt->getMorphClass())->where('subject_id', $firstAttempt->id)->where('action', 'payment.status_changed')->sole();
        $this->assertSame($entry->id, $failedActivity->festival_entry_id);
        $this->assertSame('failed', $failedActivity->payload['to_status']);
        $this->assertNull($failedActivity->actor_user_id);
        $this->assertNull($failedActivity->actor_portal_user_id);
        $this->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]))
            ->assertOk()
            ->assertSee('border-rose-300 bg-rose-50', false)
            ->assertSee(__('app.festival_payment_failed_retry'))
            ->assertSee(__('app.festival_submit_and_pay'))
            ->assertDontSee(__('app.pay_now'));

        $this->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), [
            'provider' => IntegrationProvider::Liqpay->value,
            'festival_rules_accepted' => '1',
        ])->assertOk();
        $secondAttempt = FestivalPaymentAttempt::query()->where('festival_charge_id', $charge->id)->latest('id')->firstOrFail();
        app(FestivalPaymentService::class)->completeAttempt($secondAttempt, new PaymentCallbackResult(
            orderId: $secondAttempt->order_id,
            status: PaymentCallbackStatus::Paid,
            amountCents: $secondAttempt->amount_cents,
            currency: $secondAttempt->currency,
            gatewayInvoiceId: 'invoice-portal-1',
        ));

        $this->assertSame(FestivalChargeStatus::Paid, $charge->refresh()->status);
        $this->assertSame(FestivalEntryStepStatus::Submitted, $step->refresh()->status);
        $this->assertSame(FestivalEntryStatus::Submitted, $entry->refresh()->status);
        $this->assertSame(2, FestivalPaymentAttempt::query()->where('festival_charge_id', $charge->id)->count());
        $this->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]))
            ->assertOk()
            ->assertSee('border-emerald-300 bg-emerald-50', false)
            ->assertSee(__('app.festival_charge_status_paid'))
            ->assertDontSee(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), false);

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->actingAs($owner, 'web')
            ->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]))
            ->assertOk()
            ->assertSee(__('app.festival_online_payment_time'))
            ->assertSee(__('app.festival_gateway_invoice'))
            ->assertSee('invoice-portal-1')
            ->assertSee(__('app.festival_fiscal_receipt'))
            ->assertSee(__('app.festival_fiscal_not_configured'));
    }

    public function test_manually_paid_step_keeps_the_regular_submit_action(): void
    {
        [$account, $edition, $portalUser, $participant, $category] = $this->festival();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Manually paid entry'),
        );
        $step = $this->step($entry, 'application');
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $step->id,
            'code' => 'FCH-MANUAL-'.$entry->id,
            'kind' => 'qualification',
            'name' => 'Manually paid fee',
            'status' => FestivalChargeStatus::Paid,
            'amount_cents' => 10000,
            'currency' => 'UAH',
            'paid_at' => now(),
        ]);

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]))
            ->assertOk()
            ->assertSee(route('festival.portal.entry-steps.submit', [$account->slug, $entry, $step]), false)
            ->assertDontSee(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), false)
            ->assertDontSee(__('app.festival_submit_and_pay'));
    }

    public function test_fixed_fee_and_priced_helpers_settle_in_one_payment_attempt(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $account->forceFill(['default_currency' => 'UAH'])->save();
        $paymentWorkflowStep = $workflow->steps->firstWhere('code', 'participation_payment');
        $this->requirement(
            $edition,
            $workflow,
            'participation_payment',
            'helpers',
            'integer',
            ['mode' => 'per_unit', 'unit_amount_cents' => 40000],
        )->update(['name' => 'Helpers']);
        $feeDefinition = FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $paymentWorkflowStep->id,
            'kind' => 'participation',
            'name' => 'Video selection',
            'amount_cents' => 100,
            'currency' => 'UAH',
        ]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Combined payment entry'),
        );
        $this->step($entry, 'application')->forceFill([
            'status' => FestivalEntryStepStatus::Approved,
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ])->save();
        $entry->refresh()->load('steps.workflowStep', 'steps.requirements.definition', 'steps.charges');
        $paymentStep = $this->step($entry, 'participation_payment');
        $helpersRequirement = $paymentStep->requirements->sole();
        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Liqpay,
            'category' => IntegrationCategory::Payment,
            'is_enabled' => true,
            'credentials' => ['public_key' => 'studio-public', 'private_key' => 'studio-private'],
        ]);

        $saved = $this->actingAs($portalUser, 'festival')->postJson(
            route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $paymentStep, $helpersRequirement]),
            ['value' => 2],
        )->assertOk();

        $paymentHtml = $saved->json('payment_html');
        $this->assertStringContainsString('801 ₴', $paymentHtml);
        $this->assertStringContainsString('Video selection', $paymentHtml);
        $this->assertSame(1, substr_count($paymentHtml, 'data-festival-charge-card'));
        $charges = $entry->charges()->whereIn('status', [FestivalChargeStatus::Pending->value, FestivalChargeStatus::Failed->value])->orderBy('id')->get();
        $this->assertCount(2, $charges);
        $fixedCharge = $charges->firstWhere('festival_charge_definition_id', $feeDefinition->id);
        $helperCharge = $charges->firstWhere('festival_entry_requirement_id', $helpersRequirement->id);
        $this->assertNotNull($fixedCharge);
        $this->assertNotNull($helperCharge);
        $this->assertSame(100, $fixedCharge->amount_cents);
        $this->assertSame(80000, $helperCharge->amount_cents);

        $this->post(route('festival.portal.charges.pay', [$account->slug, $entry, $fixedCharge]), [
            'provider' => IntegrationProvider::Liqpay->value,
            'festival_rules_accepted' => '1',
        ])->assertOk();

        $attempt = FestivalPaymentAttempt::query()->where('festival_charge_id', $fixedCharge->id)->sole();
        $this->assertSame(80100, $attempt->amount_cents);
        $this->assertSame([100, 80000], $attempt->allocations()->orderBy('amount_cents')->pluck('amount_cents')->all());
        app(FestivalPaymentService::class)->completeAttempt($attempt, new PaymentCallbackResult(
            orderId: $attempt->order_id,
            status: PaymentCallbackStatus::Paid,
            amountCents: 80100,
            currency: 'UAH',
        ));

        $this->assertSame(FestivalChargeStatus::Paid, $fixedCharge->refresh()->status);
        $this->assertSame(FestivalChargeStatus::Paid, $helperCharge->refresh()->status);
        $this->assertSame(FestivalEntryStepStatus::Approved, $paymentStep->refresh()->status);
        $this->assertSame(2, $attempt->allocations()->count());
    }

    public function test_checkout_groups_multiple_charges_only_by_runtime_step_and_currency(): void
    {
        [$account, $edition, $portalUser, $participant, $category] = $this->festival();
        $account->forceFill(['default_currency' => 'UAH'])->save();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Scoped payment entry'),
        );
        $paymentStep = $this->step($entry, 'application');
        $otherStep = $this->step($entry, 'technical_form');
        $feeDefinition = FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'is_active' => false,
            'name' => 'Configured fee',
            'amount_cents' => 100,
            'currency' => 'UAH',
        ]);
        $charges = collect([
            $entry->charges()->create(['account_id' => $account->id, 'festival_entry_step_id' => $paymentStep->id, 'festival_charge_definition_id' => $feeDefinition->id, 'code' => 'FCH-SCOPE-FEE-'.$entry->id, 'kind' => 'qualification', 'name' => 'Configured fee', 'amount_cents' => 100, 'currency' => 'UAH']),
            $entry->charges()->create(['account_id' => $account->id, 'festival_entry_step_id' => $paymentStep->id, 'code' => 'FCH-SCOPE-HELPERS-'.$entry->id, 'kind' => 'response_price', 'name' => 'Helpers', 'amount_cents' => 800, 'currency' => 'UAH']),
            $entry->charges()->create(['account_id' => $account->id, 'festival_entry_step_id' => $paymentStep->id, 'code' => 'FCH-SCOPE-CHAIRS-'.$entry->id, 'kind' => 'response_price', 'name' => 'Chairs', 'amount_cents' => 200, 'currency' => 'UAH']),
        ]);
        $foreignCurrencyCharge = $entry->charges()->create(['account_id' => $account->id, 'festival_entry_step_id' => $paymentStep->id, 'code' => 'FCH-SCOPE-USD-'.$entry->id, 'kind' => 'response_price', 'name' => 'USD extra', 'amount_cents' => 500, 'currency' => 'USD']);
        $otherStepCharge = $entry->charges()->create(['account_id' => $account->id, 'festival_entry_step_id' => $otherStep->id, 'code' => 'FCH-SCOPE-OTHER-'.$entry->id, 'kind' => 'response_price', 'name' => 'Later extra', 'amount_cents' => 700, 'currency' => 'UAH']);
        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Liqpay,
            'category' => IntegrationCategory::Payment,
            'is_enabled' => true,
            'credentials' => ['public_key' => 'studio-public', 'private_key' => 'studio-private'],
        ]);

        $page = $this->actingAs($portalUser, 'festival')->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $paymentStep]));
        $page->assertOk()->assertSee('11 ₴')->assertSee('5 $')->assertDontSee('Later extra');
        $this->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charges->first()]), [
            'provider' => IntegrationProvider::Liqpay->value,
            'festival_rules_accepted' => '1',
        ])->assertOk();

        $attempt = FestivalPaymentAttempt::query()->where('festival_charge_id', $charges->first()->id)->sole();
        $this->assertSame(1100, $attempt->amount_cents);
        $this->assertSame($charges->pluck('id')->sort()->values()->all(), $attempt->allocations()->pluck('festival_charge_id')->sort()->values()->all());
        $this->assertSame(FestivalChargeStatus::Pending, $foreignCurrencyCharge->refresh()->status);
        $this->assertSame(FestivalChargeStatus::Pending, $otherStepCharge->refresh()->status);
    }

    public function test_checkout_rebuilds_the_currency_group_from_locked_charge_rows(): void
    {
        [$account, $edition, $portalUser, $participant, $category] = $this->festival();
        $account->forceFill(['default_currency' => 'UAH'])->save();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Locked currency entry'),
        );
        $step = $this->step($entry, 'application');
        $feeDefinition = FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'is_active' => false,
            'name' => 'Locked fee',
            'amount_cents' => 100,
            'currency' => 'UAH',
        ]);
        $fixedCharge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $step->id,
            'festival_charge_definition_id' => $feeDefinition->id,
            'code' => 'FCH-LOCKED-FEE-'.$entry->id,
            'kind' => 'qualification',
            'name' => 'Locked fee',
            'status' => FestivalChargeStatus::PaymentPending,
            'amount_cents' => 100,
            'currency' => 'UAH',
        ]);
        $helperCharge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $step->id,
            'code' => 'FCH-LOCKED-HELPERS-'.$entry->id,
            'kind' => 'response_price',
            'name' => 'Helpers',
            'amount_cents' => 800,
            'currency' => 'UAH',
        ]);
        $expiredAttempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $fixedCharge->id,
            'provider' => IntegrationProvider::Liqpay,
            'order_id' => 'FCHP-LOCKED-'.$entry->id,
            'amount_cents' => $fixedCharge->amount_cents,
            'currency' => 'UAH',
            'expires_at' => now()->subMinute(),
        ]);
        $expiredAttempt->allocations()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $fixedCharge->id,
            'amount_cents' => $fixedCharge->amount_cents,
            'currency' => 'UAH',
        ]);
        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Liqpay,
            'category' => IntegrationCategory::Payment,
            'is_enabled' => true,
            'credentials' => ['public_key' => 'studio-public', 'private_key' => 'studio-private'],
        ]);
        $eventName = 'eloquent.retrieved: '.FestivalPaymentAttempt::class;
        $currencyChanged = false;
        Event::listen($eventName, function (FestivalPaymentAttempt $retrievedAttempt) use ($expiredAttempt, $helperCharge, &$currencyChanged): void {
            if ($currencyChanged || ! $retrievedAttempt->is($expiredAttempt)) {
                return;
            }

            FestivalCharge::query()->whereKey($helperCharge->id)->update(['currency' => 'USD']);
            $currencyChanged = true;
        });

        try {
            $this->actingAs($portalUser, 'festival')->post(route('festival.portal.charges.pay', [$account->slug, $entry, $fixedCharge]), [
                'provider' => IntegrationProvider::Liqpay->value,
                'festival_rules_accepted' => '1',
            ])->assertOk();
        } finally {
            Event::forget($eventName);
        }

        $attempt = FestivalPaymentAttempt::query()->whereKeyNot($expiredAttempt->id)->sole();
        $this->assertTrue($currencyChanged);
        $this->assertSame(100, $attempt->amount_cents);
        $this->assertSame('UAH', $attempt->currency);
        $this->assertSame([$fixedCharge->id], $attempt->allocations()->pluck('festival_charge_id')->all());
        $this->assertSame('USD', $helperCharge->refresh()->currency);
        $this->assertSame(FestivalChargeStatus::Pending, $helperCharge->status);
    }

    public function test_expired_group_attempt_is_retryable_and_non_paid_callbacks_do_not_regress_it(): void
    {
        [$account, $edition, $portalUser, $participant, $category] = $this->festival();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Expired retry entry'),
        );
        $step = $this->step($entry, 'application');
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $step->id,
            'code' => 'FCH-EXPIRED-RETRY-'.$entry->id,
            'kind' => 'qualification',
            'name' => 'Retry fee',
            'amount_cents' => 50000,
            'currency' => strtoupper($account->default_currency),
        ]);
        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Liqpay,
            'category' => IntegrationCategory::Payment,
            'is_enabled' => true,
            'credentials' => ['public_key' => 'studio-public', 'private_key' => 'studio-private'],
        ]);
        $paymentPayload = ['provider' => IntegrationProvider::Liqpay->value, 'festival_rules_accepted' => '1'];

        $this->actingAs($portalUser, 'festival')->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), $paymentPayload)->assertOk();
        $expiredAttempt = FestivalPaymentAttempt::query()->where('festival_charge_id', $charge->id)->sole();
        $expiredAttempt->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]))
            ->assertOk()
            ->assertSee(__('app.festival_payment_failed_retry'))
            ->assertSee(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), false);

        $this->post(route('festival.portal.charges.pay', [$account->slug, $entry, $charge]), $paymentPayload)->assertOk();
        $retryAttempt = FestivalPaymentAttempt::query()->where('festival_charge_id', $charge->id)->latest('id')->firstOrFail();
        $this->assertSame(FestivalPaymentStatus::Expired, $expiredAttempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);
        app(FestivalPaymentService::class)->completeAttempt($expiredAttempt, new PaymentCallbackResult(
            orderId: $expiredAttempt->order_id,
            status: PaymentCallbackStatus::Pending,
            amountCents: $expiredAttempt->amount_cents,
            currency: $expiredAttempt->currency,
        ));
        $this->assertSame(FestivalPaymentStatus::Expired, $expiredAttempt->refresh()->status);
        $this->assertSame(FestivalChargeStatus::PaymentPending, $charge->refresh()->status);

        app(FestivalPaymentService::class)->completeAttempt($retryAttempt, new PaymentCallbackResult(
            orderId: $retryAttempt->order_id,
            status: PaymentCallbackStatus::Paid,
            amountCents: $retryAttempt->amount_cents,
            currency: $retryAttempt->currency,
        ));
        $this->assertSame(FestivalChargeStatus::Paid, $charge->refresh()->status);
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
        $applicationStep = $this->step($entry, 'application');
        $urlRequirement = $applicationStep->requirements->firstWhere('festival_requirement_definition_id', $urlDefinition->id);
        $fileRequirement = $applicationStep->requirements->firstWhere('festival_requirement_definition_id', $fileDefinition->id);

        $firstValue = app(StoreFestivalResponse::class)->execute($urlRequirement, $portalUser, 'https://video.example/original');
        $latestValue = app(StoreFestivalResponse::class)->execute($urlRequirement, $portalUser, 'https://video.example/revised');
        $fileSubmission = app(StoreFestivalSubmission::class)->execute($fileRequirement, $portalUser, UploadedFile::fake()->image('private-proof.png'));

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $ownerResponse = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]));

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
            ->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]))
            ->assertOk()
            ->assertDontSee('https://video.example/original')
            ->assertDontSee('https://video.example/revised');

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entries.show', [$account->slug, $entry]))
            ->assertOk()
            ->assertSee(__('app.refresh'))
            ->assertSeeInOrder([
                route('festival.portal.entries.withdraw', [$account->slug, $entry]),
                'min-h-11',
                __('app.festival_withdraw'),
            ], false);
    }

    public function test_four_step_registration_supports_corrections_priced_answers_and_completion(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $applicationDefinition = $this->requirement($edition, $workflow, 'application', 'selection-video', 'url');
        $technicalDefinition = $this->requirement($edition, $workflow, 'technical_form', 'helpers', 'integer', ['mode' => 'per_unit', 'unit_amount_cents' => 2500]);
        $entry = $this->entry($account, $edition, $portalUser, $participant, $category, 'First category entry');

        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);

        $this->assertCount(4, $entry->steps);
        $application = $this->step($entry, 'application');
        $payment = $this->step($entry, 'participation_payment');
        $technical = $this->step($entry, 'technical_form');
        $summary = $this->step($entry, 'summary');
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
                'correction_due_at' => now()->addDay()->toDateTimeString(),
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(FestivalEntryStepStatus::ChangesRequested, $application->refresh()->status);
        $changesNotification = FestivalNotification::query()
            ->where('festival_entry_id', $entry->id)
            ->where('type', FestivalNotificationType::EntryStepReviewed->value)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('request_changes', $changesNotification->payload['decision']);
        $this->assertStringNotContainsString('request_changes', (string) $changesNotification->text);

        $correctionPage = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $application]));
        $correctionPage->assertOk()
            ->assertSee(__('app.festival_correction_submit_required'))
            ->assertSee('id="festival-entry-step-submit-'.$application->id.'"', false)
            ->assertSee('form="festival-entry-step-submit-'.$application->id.'"', false);
        $this->assertSame(2, substr_count($correctionPage->getContent(), 'data-festival-progress-action='));

        app(StoreFestivalResponse::class)->execute($application->requirements->first(), $portalUser, 'https://video.example/revised');
        $this->assertCount(1, $application->requirements->first()->submissions()->get());
        $this->actingAs($portalUser, 'festival')
            ->post(route('festival.portal.entry-steps.submit', [$account->slug, $entry, $application]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(FestivalEntryStepStatus::Submitted, $application->refresh()->status);
        app(ReviewFestivalEntryStep::class)->execute($application->refresh(), $reviewer, 'approve', 'Qualified.');
        $approvalNotification = FestivalNotification::query()
            ->where('festival_entry_id', $entry->id)
            ->where('type', FestivalNotificationType::EntryStepReviewed->value)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('approve', $approvalNotification->payload['decision']);
        $this->assertSame('payment', $approvalNotification->payload['next_step_type']);
        $this->assertStringContainsString(route('festival.portal.entry-steps.show', [$account->slug, $entry, $application]), $approvalNotification->payload['action_url']);
        $this->assertStringNotContainsString('approve', (string) $approvalNotification->text);

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
        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $summary]))
            ->assertOk()
            ->assertSee(__('app.festival_summary_awaiting_title'))
            ->assertDontSee(route('festival.portal.entry-steps.submit', [$account->slug, $entry, $summary]), false);
        $this->actingAs($reviewer, 'web')
            ->patch(route('dashboard.accounts.festivals.applications.fully-confirm', [$account, $edition, $entry]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(FestivalEntryStatus::Accepted, $entry->refresh()->status);
        $this->assertNotNull($entry->registration_completed_at);
        $this->assertSame(FestivalEntryStepStatus::Approved, $summary->refresh()->status);
    }

    public function test_existing_progress_keeps_its_workflow_while_new_configuration_initializes_only_new_entries(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $existingEntry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Existing entry'),
        );
        $existingStep = $this->step($existingEntry, 'application');
        $existingStep->forceFill(['status' => FestivalEntryStepStatus::Submitted])->save();
        $originalWorkflowStepIds = $existingEntry->steps->pluck('festival_workflow_step_id')->sort()->values();

        $lateStep = $workflow->steps()->create([
            'account_id' => $account->id,
            'code' => 'late_details',
            'type' => 'form',
            'title' => 'Late details',
            'sort_order' => 35,
            'review_mode' => 'automatic',
            'review_effect' => 'none',
            'is_active' => true,
        ]);
        $lateRequirement = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $lateStep->id,
            'code' => 'late-note',
            'type' => 'custom_document',
            'subject_scope' => 'entry',
            'input_type' => 'short_text',
            'name' => 'Late note',
            'is_active' => true,
        ]);
        $lateCharge = FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $lateStep->id,
            'kind' => 'late_fee',
            'name' => 'Late fee',
            'amount_cents' => 1500,
        ]);

        $existingEntry = app(InitializeFestivalEntryWorkflow::class)->execute($existingEntry->refresh());
        $this->assertSame($originalWorkflowStepIds->all(), $existingEntry->steps->pluck('festival_workflow_step_id')->sort()->values()->all());
        $this->assertSame(FestivalEntryStepStatus::Submitted, $existingStep->refresh()->status);
        $this->assertFalse($existingEntry->requirements()->where('festival_requirement_definition_id', $lateRequirement->id)->exists());
        $this->assertFalse($existingEntry->charges()->where('festival_charge_definition_id', $lateCharge->id)->exists());

        $newEntry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'New configuration entry'),
        );
        $this->assertTrue($newEntry->steps->contains('festival_workflow_step_id', $lateStep->id));
        $this->assertTrue($newEntry->requirements()->where('festival_requirement_definition_id', $lateRequirement->id)->exists());
        $this->assertTrue($newEntry->charges()->where('festival_charge_definition_id', $lateCharge->id)->exists());

        $lateStep->forceFill(['is_active' => false])->save();
        $afterDeactivation = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'After deactivation entry'),
        );
        $this->assertFalse($afterDeactivation->steps->contains('festival_workflow_step_id', $lateStep->id));
        $this->assertFalse($afterDeactivation->requirements()->where('festival_requirement_definition_id', $lateRequirement->id)->exists());
        $this->assertFalse($afterDeactivation->charges()->where('festival_charge_definition_id', $lateCharge->id)->exists());
        $this->assertTrue($newEntry->steps()->where('festival_workflow_step_id', $lateStep->id)->exists());

        $replacementWorkflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Replacement registration');
        $category->forceFill(['festival_workflow_id' => $replacementWorkflow->id])->save();
        $existingEntry = app(InitializeFestivalEntryWorkflow::class)->execute($existingEntry->refresh());
        $this->assertSame($originalWorkflowStepIds->all(), $existingEntry->steps->pluck('festival_workflow_step_id')->sort()->values()->all());

        $replacementEntry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Replacement workflow entry'),
        );
        $this->assertSame(
            $replacementWorkflow->steps->pluck('id')->sort()->values()->all(),
            $replacementEntry->steps->pluck('festival_workflow_step_id')->sort()->values()->all(),
        );
    }

    public function test_current_workflow_and_requirement_configuration_applies_to_existing_progress(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $definition = $this->requirement($edition, $workflow, 'technical_form', 'helpers', 'integer');
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Current configuration entry'),
        );
        $entry->steps()
            ->whereHas('workflowStep', fn ($query) => $query->whereIn('code', ['application', 'participation_payment']))
            ->update(['status' => FestivalEntryStepStatus::Approved->value]);
        $entry->load('steps.workflowStep', 'steps.requirements.definition');
        $technicalStep = $this->step($entry, 'technical_form');
        $requirement = $technicalStep->requirements->firstWhere('festival_requirement_definition_id', $definition->id);
        $workflowStep = $technicalStep->workflowStep;

        $workflowStep->forceFill(['title' => 'Current technical details'])->save();
        $definition->forceFill([
            'name' => 'Current helper package',
            'input_type' => 'single_select',
            'options' => [
                ['value' => 'basic', 'label' => 'Basic'],
                ['value' => 'extended', 'label' => 'Extended'],
            ],
            'pricing' => [
                'mode' => 'option_prices',
                'prices' => ['basic' => 2500, 'extended' => 6000],
            ],
        ])->save();

        try {
            app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 'removed-option');
            $this->fail('The current requirement options must reject removed values.');
        } catch (ValidationException) {
            $this->assertCount(0, $requirement->submissions()->get());
        }

        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 'extended');

        $this->assertSame('Current technical details', $technicalStep->refresh()->workflowStep->title);
        $this->assertSame('Current helper package', $requirement->refresh()->definition->name);
        $this->assertDatabaseHas('festival_charges', [
            'festival_entry_id' => $entry->id,
            'festival_entry_requirement_id' => $requirement->id,
            'name' => 'Current helper package',
            'amount_cents' => 6000,
        ]);
    }

    public function test_per_participant_entry_limit_is_enforced_atomically_on_first_step_submission(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $edition->forceFill(['max_entries_per_participant' => 1])->save();
        $this->requirement($edition, $workflow, 'application', 'selection-video', 'url');

        $first = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'First entry'));
        $firstStep = $this->step($first, 'application');
        app(StoreFestivalResponse::class)->execute($firstStep->requirements->first(), $portalUser, 'https://video.example/one');
        app(SubmitFestivalEntryStep::class)->execute($first, $firstStep);

        $second = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'Second entry'));
        $secondStep = $this->step($second, 'application');
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

    public function test_summary_cannot_be_submitted_by_an_applicant_and_organizer_confirmation_respects_capacity(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category] = $this->festival();
        $category->forceFill(['maximum_accepted_entries' => 1])->save();
        $occupyingEntry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::ChangesPending,
            'accepted_at' => now(),
            'registration_completed_at' => now(),
        ]);
        $candidate = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Capacity candidate'),
        );
        $candidate->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('code', '!=', 'summary'))
            ->update(['status' => FestivalEntryStepStatus::Approved->value, 'reviewed_at' => now()]);
        $candidate->refresh()->load('steps.workflowStep');
        $summaryStep = $this->step($candidate, 'summary');
        $candidate->forceFill(['status' => FestivalEntryStatus::Submitted, 'submitted_at' => now()])->save();

        $this->actingAs($portalUser, 'festival')
            ->post(route('festival.portal.entry-steps.submit', [$account->slug, $candidate, $summaryStep]))
            ->assertRedirect()
            ->assertSessionHasErrors('step');
        $this->assertSame(FestivalEntryStatus::Submitted, $candidate->refresh()->status);
        $this->assertSame(FestivalEntryStepStatus::Draft, $summaryStep->refresh()->status);

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->actingAs($owner, 'web')
            ->patch(route('dashboard.accounts.festivals.applications.fully-confirm', [$account, $edition, $candidate]))
            ->assertRedirect()
            ->assertSessionHasErrors('festival_category_id');

        $occupyingEntry->forceFill([
            'status' => FestivalEntryStatus::Rejected,
            'registration_completed_at' => null,
        ])->save();
        $this->actingAs($owner, 'web')
            ->patch(route('dashboard.accounts.festivals.applications.fully-confirm', [$account, $edition, $candidate]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(FestivalEntryStatus::Accepted, $candidate->refresh()->status);
        $this->assertNotNull($candidate->accepted_at);
        $this->assertNotNull($candidate->registration_completed_at);
        $this->assertSame(FestivalEntryStepStatus::Approved, $summaryStep->refresh()->status);
        $this->assertSame(FestivalEntryStatus::Accepted->value, FestivalNotification::query()
            ->where('festival_entry_id', $candidate->id)
            ->where('type', FestivalNotificationType::EntryReviewed->value)
            ->latest('id')
            ->firstOrFail()
            ->payload['status']);
        $this->assertDatabaseHas((new FestivalActivityLog)->getTable(), [
            'festival_entry_id' => $candidate->id,
            'action' => 'entry.reviewed',
        ]);
    }

    public function test_staff_application_uses_readiness_and_decision_aware_confirmation_modals(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category] = $this->festival();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Confirmation safeguards'),
        );
        $applicationStep = $this->step($entry, 'application');
        $applicationStep->forceFill(['status' => FestivalEntryStepStatus::Submitted])->save();
        $entry->forceFill([
            'status' => FestivalEntryStatus::Submitted,
            'submitted_at' => now(),
        ])->save();
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $applicationStep->id,
            'code' => 'FCH-CONFIRM-SAFEGUARD',
            'kind' => 'participation',
            'name' => 'Manual confirmation fee',
            'status' => FestivalChargeStatus::Pending,
            'amount_cents' => 12500,
            'currency' => 'UAH',
        ]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $applicationUrl = route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]);

        $blocked = $this->actingAs($owner, 'web')->get($applicationUrl);
        $blocked->assertOk()
            ->assertSee('data-confirm-blocked="true"', false)
            ->assertSee(__('app.festival_full_confirm_blocked_title'))
            ->assertSee($applicationStep->workflowStep->title)
            ->assertSee(__('app.festival_step_status_submitted'))
            ->assertSee('data-festival-decision-form', false)
            ->assertSee('data-confirm-action', false)
            ->assertSee(__('app.festival_review_approve'))
            ->assertSee(__('app.festival_review_return_for_correction'))
            ->assertSee(__('app.festival_review_reject_entry'))
            ->assertSee(__('app.festival_review_approve_confirm_title'))
            ->assertSee('"comment_required":true', false)
            ->assertSee('"deadline_required":true', false)
            ->assertSee(__('app.festival_manual_payment_confirm'))
            ->assertSee(__('app.festival_manual_payment_reject'))
            ->assertSee(__('app.festival_manual_payment_confirm_title'))
            ->assertSee($charge->name)
            ->assertSee('125 ₴');
        $this->assertSame(2, substr_count($blocked->getContent(), 'data-festival-decision-form'));

        $this->actingAs($owner, 'web')
            ->from($applicationUrl)
            ->patch(route('dashboard.accounts.festivals.applications.fully-confirm', [$account, $edition, $entry]))
            ->assertRedirect($applicationUrl)
            ->assertSessionHasErrors('festival_application');
        $this->assertSame(FestivalEntryStatus::Submitted, $entry->refresh()->status);

        $entry->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('code', '!=', 'summary'))
            ->update(['status' => FestivalEntryStepStatus::Approved->value, 'reviewed_at' => now()]);
        $charge->forceFill(['status' => FestivalChargeStatus::Paid, 'paid_at' => now()])->save();

        $ready = $this->actingAs($owner, 'web')->get($applicationUrl);
        $ready->assertOk()
            ->assertDontSee('data-confirm-blocked="true"', false)
            ->assertSee(__('app.festival_full_confirm_title'));

        $this->actingAs($owner, 'web')
            ->patch(route('dashboard.accounts.festivals.applications.fully-confirm', [$account, $edition, $entry]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(FestivalEntryStatus::Accepted, $entry->refresh()->status);
    }

    public function test_lowering_a_paid_priced_answer_creates_a_non_blocking_refund_adjustment(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $this->requirement($edition, $workflow, 'technical_form', 'helpers', 'integer', ['mode' => 'per_unit', 'unit_amount_cents' => 1000]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'Priced entry'));
        $entry->steps()
            ->whereHas('workflowStep', fn ($query) => $query->whereIn('code', ['application', 'participation_payment']))
            ->update(['status' => FestivalEntryStepStatus::Approved->value]);
        $entry->load('steps.workflowStep', 'steps.requirements.definition');
        $technical = $this->step($entry, 'technical_form');
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

    public function test_helper_selection_syncs_owned_helpers_and_prices_the_validated_relation_count(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $helpers = FestivalParticipant::factory()->count(2)->for($portalUser)->create([
            'account_id' => $account->id,
            'member_type' => FestivalTeamMemberType::Helper,
        ]);
        $definition = $this->requirement($edition, $workflow, 'technical_form', 'stage_helpers', 'helper_selection', [
            'mode' => 'per_unit',
            'unit_amount_cents' => 750,
        ]);
        $definition->forceFill(['type' => FestivalRequirementType::HelperSelection])->save();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Helper-priced entry'),
        );
        $entry->steps()->whereHas('workflowStep', fn ($query) => $query->whereIn('code', ['application', 'participation_payment']))->update([
            'status' => FestivalEntryStepStatus::Approved->value,
            'reviewed_at' => now(),
        ]);
        $entry->refresh()->load('steps.workflowStep', 'steps.requirements.definition');
        $technicalStep = $this->step($entry, 'technical_form');
        $requirement = $technicalStep->requirements->sole();

        $submission = app(StoreFestivalResponse::class)->execute($requirement, $portalUser, [
            'enabled' => '1',
            'helper_ids' => $helpers->modelKeys(),
        ]);

        $this->assertSame(['enabled' => true], $submission->value_json['value']);
        $this->assertSame($helpers->modelKeys(), $requirement->selectedHelpers()->pluck('festival_participants.id')->all());
        $this->assertSame(1500, $entry->charges()->where('festival_entry_requirement_id', $requirement->id)->sole()->amount_cents);
        $page = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $technicalStep]));
        $page->assertOk()->assertSee('name="value[enabled]"', false)->assertSee('name="value[helper_ids][]"', false);
        foreach ($helpers as $helper) {
            $page->assertSee($helper->displayName());
        }

        $this->actingAs($portalUser, 'festival')
            ->putJson(route('festival.portal.participants.update', [$account->slug, $helpers->first()]), [
                'first_name' => $helpers->first()->first_name,
                'last_name' => $helpers->first()->last_name,
                'date_of_birth' => $helpers->first()->date_of_birth->toDateString(),
                'member_type' => FestivalTeamMemberType::Performer->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('member_type');
        $this->assertSame(FestivalTeamMemberType::Helper, $helpers->first()->refresh()->member_type);

        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, ['enabled' => '0']);

        $this->assertSame(0, $requirement->selectedHelpers()->count());
        $this->assertTrue($requirement
            ->unsetRelation('definition')
            ->unsetRelation('submissions')
            ->unsetRelation('selectedHelpers')
            ->hasSubmittedResponse());
        $this->assertSame(
            FestivalChargeStatus::Cancelled,
            $entry->charges()->where('festival_entry_requirement_id', $requirement->id)->sole()->status,
        );
    }

    public function test_empty_helper_selection_offers_the_pretyped_add_helper_modal(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $definition = $this->requirement($edition, $workflow, 'technical_form', 'stage_helpers', 'helper_selection', [
            'mode' => 'per_unit',
            'unit_amount_cents' => 500,
        ]);
        $definition->forceFill(['type' => FestivalRequirementType::HelperSelection])->save();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Helper modal entry'),
        );
        $entry->steps()->whereHas('workflowStep', fn ($query) => $query->whereIn('code', ['application', 'participation_payment']))->update([
            'status' => FestivalEntryStepStatus::Approved->value,
            'reviewed_at' => now(),
        ]);
        $entry->refresh()->load('steps.workflowStep', 'steps.requirements.definition');
        $technicalStep = $this->step($entry, 'technical_form');

        $page = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $technicalStep]));

        $page->assertOk()
            ->assertSee(__('app.festival_helpers_empty'))
            ->assertSee('data-festival-helper-add', false)
            ->assertSee('data-festival-team-modal', false)
            ->assertSee('value="helper"', false)
            ->assertSee('name="value[enabled]"', false);
        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*name="member_type")(?=[^>]*value="helper")(?=[^>]*checked)[^>]*>/s',
            $page->getContent(),
        );
    }

    public function test_first_application_roster_offers_a_pretyped_add_performer_modal_and_selected_fragment(): void
    {
        [$account, $edition, $portalUser, , $category] = $this->festival();
        $createUrl = route('festival.portal.entries.create', [$account->slug, $edition->slug]);

        $page = $this->actingAs($portalUser, 'festival')->get($createUrl);

        $page->assertOk()
            ->assertSee('data-festival-performer-add', false)
            ->assertSee('data-festival-performer-options', false)
            ->assertSee('data-festival-team-modal-context="performer_selection"', false)
            ->assertSee(route('festival.portal.participants.index', [
                'accountSlug' => $account->slug,
                'add' => FestivalTeamMemberType::Performer->value,
            ]), false);
        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*name="member_type")(?=[^>]*value="performer")(?=[^>]*checked)[^>]*>/s',
            $page->getContent(),
        );

        $created = $this->withHeader('Accept', 'application/json')
            ->post(route('festival.portal.participants.store', $account->slug), [
                'first_name' => 'New',
                'last_name' => 'Performer',
                'date_of_birth' => '2001-01-01',
                'member_type' => FestivalTeamMemberType::Performer->value,
                'fragment_context' => 'performer_selection',
            ]);

        $created->assertOk()
            ->assertJsonPath('message', __('app.festival_portal_team_saved'))
            ->assertJsonPath('helper_option_html', null);
        $performerOption = $created->json('performer_option_html');
        $this->assertIsString($performerOption);
        $this->assertStringContainsString('data-festival-performer-option', $performerOption);
        $this->assertStringContainsString('name="participant_ids[]"', $performerOption);
        $this->assertStringContainsString('checked', $performerOption);

        $newPerformer = $portalUser->participants()->where('first_name', 'New')->sole();
        $this->assertSame(FestivalTeamMemberType::Performer, $newPerformer->member_type);

        $this->from($createUrl)->post(route('festival.portal.entries.store', [$account->slug, $edition->slug]), [
            'festival_category_id' => $category->id,
            'participant_ids' => [$newPerformer->id],
            'entry_name' => 'New inline performer',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(
            [$newPerformer->id],
            $portalUser->entries()->sole()->participants()->pluck('festival_participants.id')->all(),
        );
    }

    public function test_first_application_draft_locks_profile_type_and_only_performers_can_enter_the_roster(): void
    {
        [$account, $edition, $portalUser, $performer, $category] = $this->festival();
        $performer->forceFill([
            'first_name' => 'AllowedPerformer',
            'member_type' => FestivalTeamMemberType::Performer,
        ])->save();
        $helper = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'first_name' => 'ForbiddenHelper',
            'member_type' => FestivalTeamMemberType::Helper,
        ]);
        $createUrl = route('festival.portal.entries.create', [$account->slug, $edition->slug]);
        $storeUrl = route('festival.portal.entries.store', [$account->slug, $edition->slug]);

        $this->actingAs($portalUser, 'festival')
            ->get($createUrl)
            ->assertOk()
            ->assertSee('AllowedPerformer')
            ->assertDontSee('ForbiddenHelper');

        $this->from($createUrl)->post($storeUrl, [
            'festival_category_id' => $category->id,
            'participant_ids' => [$helper->id],
            'entry_name' => 'Forged helper roster',
        ])->assertRedirect($createUrl)->assertSessionHasErrors('participant_ids');
        $this->assertSame(0, $portalUser->entries()->count());
        $this->assertNull($portalUser->refresh()->registrant_type_locked_at);

        $this->post($storeUrl, [
            'festival_category_id' => $category->id,
            'participant_ids' => [$performer->id],
            'entry_name' => 'Valid performer roster',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $entry = $portalUser->entries()->sole();
        $this->assertNotNull($portalUser->refresh()->registrant_type_locked_at);
        $this->assertSame([$performer->id], $entry->participants()->pluck('festival_participants.id')->all());

        $editUrl = route('festival.portal.entries.edit', [$account->slug, $entry]);
        $this->from($editUrl)->put(route('festival.portal.entries.update', [$account->slug, $entry]), [
            'festival_category_id' => $category->id,
            'participant_ids' => [$helper->id],
            'entry_name' => 'Forged replacement roster',
        ])->assertRedirect($editUrl)->assertSessionHasErrors('participant_ids');
        $this->assertSame([$performer->id], $entry->participants()->pluck('festival_participants.id')->all());

        app(DeleteFestivalEntry::class)->execute($entry, User::factory()->create());
        $this->assertNotNull($portalUser->refresh()->registrant_type_locked_at);
        $profileUrl = route('festival.portal.profile.edit', $account->slug);
        $this->from($profileUrl)->put(route('festival.portal.profile.update', $account->slug), [
            'registrant_type' => 'adult_athlete',
            'first_name' => $portalUser->first_name,
            'last_name' => $portalUser->last_name,
            'date_of_birth' => '2000-01-01',
            'email' => $portalUser->email,
            'phone' => $portalUser->phone,
            'city' => $portalUser->city,
            'studio_name' => $portalUser->studio_name,
            'locale' => $portalUser->locale,
        ])->assertRedirect($profileUrl)->assertSessionHasErrors('registrant_type');
        $this->assertSame('coach', $portalUser->refresh()->registrant_type->value);
    }

    public function test_helper_selection_rejects_unowned_inactive_wrong_type_and_duplicate_members_atomically(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $validHelper = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'member_type' => FestivalTeamMemberType::Helper,
        ]);
        $archivedHelper = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'member_type' => FestivalTeamMemberType::Helper,
            'archived_at' => now(),
        ]);
        $otherPortalUser = FestivalPortalUser::factory()->for($account)->create();
        $unownedHelper = FestivalParticipant::factory()->for($otherPortalUser)->create([
            'account_id' => $account->id,
            'member_type' => FestivalTeamMemberType::Helper,
        ]);
        $definition = $this->requirement($edition, $workflow, 'technical_form', 'stage_helpers', 'helper_selection', [
            'mode' => 'per_unit',
            'unit_amount_cents' => 1000,
        ]);
        $definition->forceFill(['type' => FestivalRequirementType::HelperSelection])->save();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Scoped helper entry'),
        );
        $entry->steps()->whereHas('workflowStep', fn ($query) => $query->whereIn('code', ['application', 'participation_payment']))->update([
            'status' => FestivalEntryStepStatus::Approved->value,
            'reviewed_at' => now(),
        ]);
        $entry->refresh()->load('steps.workflowStep', 'steps.requirements.definition');
        $requirement = $this->step($entry, 'technical_form')->requirements->sole();
        $store = app(StoreFestivalResponse::class);
        $submission = $store->execute($requirement, $portalUser, [
            'enabled' => true,
            'helper_ids' => [$validHelper->id],
        ]);

        foreach ([
            [$participant->id],
            [$archivedHelper->id],
            [$unownedHelper->id],
            [$validHelper->id, $validHelper->id],
            [],
        ] as $invalidHelperIds) {
            try {
                $store->execute($requirement, $portalUser, [
                    'enabled' => true,
                    'helper_ids' => $invalidHelperIds,
                ]);
                $this->fail('Invalid helpers must not be accepted.');
            } catch (ValidationException $exception) {
                $this->assertTrue(collect(array_keys($exception->errors()))
                    ->contains(fn (string $key): bool => str($key)->startsWith('value.helper_ids')));
            }

            $this->assertSame([$validHelper->id], $requirement->selectedHelpers()->pluck('festival_participants.id')->all());
            $this->assertSame(['enabled' => true], $submission->refresh()->value_json['value']);
            $this->assertSame(1000, $entry->charges()->where('festival_entry_requirement_id', $requirement->id)->sole()->amount_cents);
        }
    }

    public function test_paid_helper_selection_keeps_existing_supplement_and_refund_guarantees(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $helpers = FestivalParticipant::factory()->count(3)->for($portalUser)->create([
            'account_id' => $account->id,
            'member_type' => FestivalTeamMemberType::Helper,
        ]);
        $definition = $this->requirement($edition, $workflow, 'technical_form', 'stage_helpers', 'helper_selection', [
            'mode' => 'per_unit',
            'unit_amount_cents' => 1000,
        ]);
        $definition->forceFill(['type' => FestivalRequirementType::HelperSelection])->save();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute(
            $this->entry($account, $edition, $portalUser, $participant, $category, 'Paid helper entry'),
        );
        $entry->steps()->whereHas('workflowStep', fn ($query) => $query->whereIn('code', ['application', 'participation_payment']))->update([
            'status' => FestivalEntryStepStatus::Approved->value,
            'reviewed_at' => now(),
        ]);
        $entry->refresh()->load('steps.workflowStep', 'steps.requirements.definition');
        $requirement = $this->step($entry, 'technical_form')->requirements->sole();
        $store = app(StoreFestivalResponse::class);
        $store->execute($requirement, $portalUser, [
            'enabled' => true,
            'helper_ids' => $helpers->take(2)->modelKeys(),
        ]);
        $paidCharge = $entry->charges()->where('festival_entry_requirement_id', $requirement->id)->sole();
        $paidCharge->forceFill(['status' => FestivalChargeStatus::Paid, 'paid_at' => now()])->save();

        $store->execute($requirement, $portalUser, [
            'enabled' => true,
            'helper_ids' => [$helpers->first()->id],
        ]);
        $this->assertSame(1000, $entry->chargeAdjustments()->where('festival_entry_requirement_id', $requirement->id)->sole()->amount_cents);

        $store->execute($requirement, $portalUser, [
            'enabled' => true,
            'helper_ids' => $helpers->modelKeys(),
        ]);
        $this->assertSame(
            1000,
            $entry->charges()->where('festival_entry_requirement_id', $requirement->id)->where('status', FestivalChargeStatus::Pending->value)->sole()->amount_cents,
        );
        $this->assertSame('cancelled', $entry->chargeAdjustments()->where('festival_entry_requirement_id', $requirement->id)->sole()->status);
    }

    public function test_unpaid_priced_response_reuses_one_charge_through_zero_and_back(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $this->requirement($edition, $workflow, 'technical_form', 'helpers', 'integer', ['mode' => 'per_unit', 'unit_amount_cents' => 1000]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'Mutable priced entry'));
        $entry->steps()->whereHas('workflowStep', fn ($query) => $query->whereIn('code', ['application', 'participation_payment']))->update([
            'status' => FestivalEntryStepStatus::Approved->value,
            'reviewed_at' => now(),
        ]);
        $entry->refresh()->load('steps.workflowStep', 'steps.requirements.definition');
        $requirement = $this->step($entry, 'technical_form')->requirements->sole();

        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 2);
        $charge = $entry->charges()->where('festival_entry_requirement_id', $requirement->id)->sole();
        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 0);
        $this->assertSame(FestivalChargeStatus::Cancelled, $charge->refresh()->status);
        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 1);

        $this->assertSame(1, $entry->charges()->where('festival_entry_requirement_id', $requirement->id)->count());
        $this->assertSame(FestivalChargeStatus::Pending, $charge->refresh()->status);
        $this->assertSame(1000, $charge->amount_cents);
    }

    public function test_paid_priced_response_increase_creates_a_supplement_and_refund_edits_reuse_the_pending_adjustment(): void
    {
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $this->requirement($edition, $workflow, 'technical_form', 'helpers', 'integer', ['mode' => 'per_unit', 'unit_amount_cents' => 1000]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'Supplement entry'));
        $entry->steps()->whereHas('workflowStep', fn ($query) => $query->whereIn('code', ['application', 'participation_payment']))->update([
            'status' => FestivalEntryStepStatus::Approved->value,
            'reviewed_at' => now(),
        ]);
        $entry->refresh()->load('steps.workflowStep', 'steps.requirements.definition');
        $requirement = $this->step($entry, 'technical_form')->requirements->sole();
        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 2);
        $paidCharge = $entry->charges()->where('festival_entry_requirement_id', $requirement->id)->sole();
        $paidCharge->forceFill(['status' => FestivalChargeStatus::Paid, 'paid_at' => now()])->save();

        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 3);
        $supplement = $entry->charges()->where('festival_entry_requirement_id', $requirement->id)->where('status', FestivalChargeStatus::Pending->value)->sole();
        $this->assertSame(1000, $supplement->amount_cents);
        $this->assertNull($supplement->pricing_key);
        $supplement->forceFill(['status' => FestivalChargeStatus::Cancelled, 'cancelled_at' => now()])->save();

        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 1);
        $adjustment = $entry->chargeAdjustments()->where('festival_entry_requirement_id', $requirement->id)->sole();
        $this->assertSame('pending', $adjustment->status);
        $this->assertSame(1000, $adjustment->amount_cents);
        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 2);
        $this->assertSame('cancelled', $adjustment->refresh()->status);
        app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 0);

        $this->assertSame(1, $entry->chargeAdjustments()->where('festival_entry_requirement_id', $requirement->id)->count());
        $this->assertSame('pending', $adjustment->refresh()->status);
        $this->assertSame(2000, $adjustment->amount_cents);
    }

    public function test_cross_currency_paid_response_repricing_is_rejected_atomically(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser, $participant, $category, $workflow] = $this->festival();
        $this->requirement($edition, $workflow, 'technical_form', 'helpers', 'integer', ['mode' => 'per_unit', 'unit_amount_cents' => 1000]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'Cross-currency entry'));
        $entry->steps()
            ->whereHas('workflowStep', fn ($query) => $query->whereIn('code', ['application', 'participation_payment']))
            ->update(['status' => FestivalEntryStepStatus::Approved->value]);
        $entry->load('steps.workflowStep', 'steps.requirements.definition');
        $requirement = $this->step($entry, 'technical_form')->requirements->first();
        $submission = app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 3);
        $paidCharge = $entry->charges()->where('festival_submission_id', $submission->id)->firstOrFail();
        $paidCharge->forceFill(['status' => FestivalChargeStatus::Paid, 'paid_at' => now(), 'currency' => 'UAH'])->save();
        $account->update(['default_currency' => 'USD']);

        try {
            app(StoreFestivalResponse::class)->execute($requirement, $portalUser, 1);
            $this->fail('A paid response in another currency must not be repriced automatically.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('value', $exception->errors());
        }

        $this->assertSame(3, data_get($submission->refresh()->value_json, 'value'));
        $this->assertSame(3000, $paidCharge->refresh()->amount_cents);
        $this->assertSame('UAH', $paidCharge->currency);
        $this->assertSame(0, $entry->chargeAdjustments()->count());
        $this->assertSame(1, $entry->charges()->where('festival_entry_requirement_id', $requirement->id)->count());
    }

    public function test_portal_groups_pending_refunds_by_stored_currency(): void
    {
        [$account, $edition, $portalUser, $participant, $category] = $this->festival();
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($this->entry($account, $edition, $portalUser, $participant, $category, 'Mixed refund entry'));
        foreach ([['UAH', 1000], ['USD', 2500]] as [$currency, $amountCents]) {
            $entry->chargeAdjustments()->create([
                'account_id' => $account->id,
                'idempotency_key' => 'mixed-refund-'.strtolower($currency).'-'.$entry->id,
                'direction' => 'refund',
                'status' => 'pending',
                'amount_cents' => $amountCents,
                'currency' => $currency,
            ]);
        }

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entries.show', [$account->slug, $entry]))
            ->assertOk()
            ->assertSee('10 ₴')
            ->assertSee('25 $');
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
        ]]);

        return $entry;
    }

    private function step(FestivalEntry $entry, string $code): FestivalEntryStep
    {
        return $entry->steps->first(fn (FestivalEntryStep $step): bool => $step->workflowStep->code === $code);
    }
}
