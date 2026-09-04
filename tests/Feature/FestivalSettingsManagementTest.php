<?php

namespace Tests\Feature;

use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Enums\AccountRole;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalWorkflowStepType;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalContentSection;
use App\Models\FestivalDirection;
use App\Models\FestivalDocument;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalMedia;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use App\Models\User;
use App\Support\Festivals\FestivalRequirementDeadlineResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FestivalSettingsManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_manage_directions_categories_registration_fields_fees_and_content(): void
    {
        [$account, $edition, $owner] = $this->festival();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.directions.store', [$account, $edition]), [
            'name' => 'Повітряне кільце',
        ])->assertRedirect();
        $direction = FestivalDirection::query()->where('festival_edition_id', $edition->id)->where('code', 'povitryane-kiltse')->firstOrFail();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.workflows.store', [$account, $edition]), [
            'name' => 'Основна реєстрація',
            'application_review_mode' => 'organizer',
            'technical_review_mode' => 'automatic',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.workflows', [$account, $edition]));
        $workflow = FestivalWorkflow::query()->where('festival_edition_id', $edition->id)->where('name', 'Основна реєстрація')->with('steps')->firstOrFail();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.categories.store', [$account, $edition]), [
            'name' => 'Соло — кільце',
            'festival_direction_id' => $direction->id,
            'festival_workflow_id' => $workflow->id,
            'min_members' => 1,
            'max_members' => 1,
            'requirements_html' => '<h2 onclick="bad()">Умови</h2><script>bad()</script><p style="text-align: center; color: red;">Безпечний текст.</p>',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]));
        $category = FestivalCategory::query()->where('festival_edition_id', $edition->id)->where('code', 'solo-kiltse')->firstOrFail();
        $this->assertTrue($category->direction->is($direction));
        $this->assertSame('<h2>Умови</h2><p style="text-align: center;">Безпечний текст.</p>', $category->requirements_html);

        $applicationStep = $workflow->steps->first();
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), [
            'festival_category_id' => $category->id,
            'festival_workflow_step_id' => $applicationStep->id,
            'type' => 'qualification_video',
            'subject_scope' => 'entry',
            'input_type' => 'file',
            'name' => 'Кваліфікаційне відео',
            'pricing_mode' => 'none',
            'stage' => 'qualification',
            'max_size_kb' => 51200,
            'allowed_extensions_text' => 'mp4, mov',
            'allowed_mime_types_text' => 'video/mp4, video/quicktime',
            'is_required' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]));
        $registrationField = FestivalRequirementDefinition::query()->where('festival_edition_id', $edition->id)->where('code', 'kvalifikatsiyne-video')->firstOrFail();
        $this->assertSame(['mp4', 'mov'], $registrationField->allowed_extensions);
        $this->assertSame('final', $registrationField->stage);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]))
            ->assertOk()
            ->assertSee('Поля реєстрації')
            ->assertSee('Кваліфікаційне відео');

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]), [
            'festival_category_id' => $category->id,
            'festival_workflow_step_id' => $applicationStep->id,
            'kind' => 'qualification',
            'name' => 'Кваліфікаційний внесок',
            'amount' => '500.00',
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.fees', [$account, $edition]));
        $fee = FestivalChargeDefinition::query()->where('festival_edition_id', $edition->id)->firstOrFail();
        $this->assertSame(50000, $fee->amount_cents);
        $this->assertSame(strtoupper($account->default_currency), $fee->currency);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.content.store', [$account, $edition]), [
            'title' => 'Для учасників',
            'body_html' => '<p>Актуальна інформація.</p>',
            'visibility' => 'public',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.content.sections', [$account, $edition]));
        $this->assertSame('dlya-uchasnykiv', FestivalContentSection::query()->where('festival_edition_id', $edition->id)->firstOrFail()->key);
    }

    public function test_fee_list_shows_days_after_approval(): void
    {
        [$account, $edition, $owner] = $this->festival();
        FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Approval-relative fee',
            'due_policy' => 'approval_relative',
            'due_days_after_approval' => 30,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.fees', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_charge_due_days_after_approval'))
            ->assertSee('data-festival-fee-due-days="30"', false);
    }

    public function test_registration_field_form_groups_and_explains_settings_without_legacy_stage(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $step = FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create(['account_id' => $account->id]);
        $requirement = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $step->id,
            'stage' => 'qualification',
        ]);
        $this->assertFalse($requirement->show_in_media_report);

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.requirements.edit', [$account, $edition, $requirement]));

        $response
            ->assertOk()
            ->assertDontSee('name="stage"', false)
            ->assertSee('aria-controls="requirement-answer-scope-help"', false)
            ->assertSee(__('app.festival_registration_field_scope_help'))
            ->assertSee(__('app.festival_registration_field_due_at_help'))
            ->assertSee(__('app.festival_requirement_section_definition'))
            ->assertSee(__('app.festival_requirement_section_placement'))
            ->assertSee(__('app.festival_requirement_section_response'))
            ->assertSee(__('app.festival_requirement_section_commercial'))
            ->assertSee(__('app.festival_requirement_section_availability'))
            ->assertSee('name="show_in_media_report"', false)
            ->assertSee(__('app.festival_show_in_media_report_help'))
            ->assertSee('value="agreement"', false)
            ->assertSee(__('app.festival_input_agreement'));
        $this->assertSame(5, substr_count($response->getContent(), 'data-requirement-section'));
        $this->assertSame(25, substr_count($response->getContent(), 'data-field-help-toggle'));
        $this->assertSame(25, substr_count($response->getContent(), 'data-field-help-popover'));

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.requirements.update', [$account, $edition, $requirement]), [
            'festival_workflow_step_id' => $step->id,
            'type' => 'custom_document',
            'subject_scope' => 'entry',
            'input_type' => 'agreement',
            'name' => 'Updated field',
            'pricing_mode' => 'none',
            'max_size_kb' => 20480,
            'is_required' => 0,
            'is_active' => 1,
            'show_in_media_report' => 1,
            'due_reference' => 'registration_opens_at',
            'due_offset_days' => 3,
            'allow_post_confirmation_edits' => 1,
            'editable_until_reference' => 'starts_at',
            'editable_until_offset_days' => -10,
        ])->assertSessionHasNoErrors();

        $this->assertSame('qualification', $requirement->refresh()->stage);
        $this->assertSame('agreement', $requirement->input_type->value);
        $this->assertTrue($requirement->is_required);
        $this->assertTrue($requirement->show_in_media_report);
        $this->assertSame([
            'reference' => 'registration_opens_at',
            'offset_days' => 3,
        ], data_get($requirement->validation, 'due_rule'));
        $this->assertTrue(data_get($requirement->validation, 'allow_post_confirmation_edits'));
        $this->assertSame([
            'reference' => 'starts_at',
            'offset_days' => -10,
        ], data_get($requirement->validation, 'editable_until_rule'));

        $resolver = app(FestivalRequirementDeadlineResolver::class);
        $firstResolvedDueAt = $resolver->dueAt($requirement);
        $edition->forceFill(['registration_opens_at' => $edition->registration_opens_at->copy()->addDays(7)])->save();
        $this->assertTrue($firstResolvedDueAt->copy()->addDays(7)->equalTo($resolver->dueAt($requirement->unsetRelation('edition'))));

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.requirements.update', [$account, $edition, $requirement]), [
            'festival_workflow_step_id' => $step->id,
            'type' => 'custom_document',
            'subject_scope' => 'entry',
            'input_type' => 'agreement',
            'name' => 'Updated field',
            'pricing_mode' => 'none',
            'max_size_kb' => 20480,
            'is_required' => 0,
            'is_active' => 1,
            'show_in_media_report' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertFalse($requirement->refresh()->show_in_media_report);
    }

    public function test_registration_fields_list_shows_media_report_and_post_confirmation_editing_columns(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $step = FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create(['account_id' => $account->id]);
        $requirement = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $step->id,
            'name' => 'Music upload',
            'show_in_media_report' => true,
            'validation' => [
                'allow_post_confirmation_edits' => true,
                'editable_until_rule' => ['reference' => 'starts_at', 'offset_days' => -10],
            ],
        ]);
        $editableUntil = app(FestivalRequirementDeadlineResolver::class)
            ->editableUntil($requirement)
            ->timezone($edition->timezone)
            ->format('d.m.Y H:i');

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]));

        $response
            ->assertOk()
            ->assertSee(__('app.festival_media_report'))
            ->assertSee(__('app.festival_allow_post_confirmation_edits'))
            ->assertSee(__('app.festival_editable_until_value', ['date' => $editableUntil]))
            ->assertSee('lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.55fr)_minmax(0,0.85fr)_15rem]', false);
        $this->assertSame(2, substr_count(
            $response->getContent(),
            'lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.55fr)_minmax(0,0.85fr)_15rem]',
        ));
    }

    public function test_content_sections_use_the_rich_text_editor_and_can_be_permanently_deleted_with_tenant_guards(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $section = FestivalContentSection::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'key' => 'jury',
            'title' => 'Jury',
            'body_html' => '<p>Authored jury</p>',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.content.edit', [$account, $edition, $section]))
            ->assertOk()
            ->assertSee('data-studio-rules-editor', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.content.sections', [$account, $edition]))
            ->assertOk()
            ->assertSee(route('dashboard.accounts.festivals.content.destroy', [$account, $edition, $section]), false)
            ->assertSee(__('app.festival_delete_content_section_title'));

        $otherEdition = FestivalEdition::factory()->published()->for($edition->series)->create([
            'account_id' => $account->id,
        ]);
        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.content.destroy', [$account, $otherEdition, $section]))
            ->assertNotFound();
        $this->assertModelExists($section);

        [$otherAccount, $crossAccountEdition] = $this->festival();
        $crossAccountSection = FestivalContentSection::query()->create([
            'account_id' => $otherAccount->id,
            'festival_edition_id' => $crossAccountEdition->id,
            'key' => 'other-jury',
            'title' => 'Other jury',
        ]);
        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.content.destroy', [$account, $edition, $crossAccountSection]))
            ->assertNotFound();
        $this->assertModelExists($crossAccountSection);

        $finance = User::factory()->create();
        $account->users()->attach($finance->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageFestivalFinance->value],
        ]);
        $this->actingAs($finance)
            ->delete(route('dashboard.accounts.festivals.content.destroy', [$account, $edition, $section]))
            ->assertForbidden();
        $this->assertModelExists($section);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.content.destroy', [$account, $edition, $section]))
            ->assertRedirect(route('dashboard.accounts.festivals.settings.content.sections', [$account, $edition]))
            ->assertSessionHas('status', __('app.festival_content_deleted'));
        $this->assertModelMissing($section);
    }

    public function test_media_list_previews_stored_and_external_images_through_the_resolved_url(): void
    {
        Storage::fake('public');
        [$account, $edition, $owner] = $this->festival();
        $path = 'festival-media/'.$account->id.'/'.$edition->id.'/stored-cover.png';
        Storage::disk('public')->put($path, 'image contents');
        $storedMedia = FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'kind' => 'image',
            'disk' => 'public',
            'path' => $path,
            'external_url' => null,
            'alt_text' => 'Stored cover',
        ]);
        $externalMedia = FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'kind' => 'image',
            'external_url' => 'https://example.test/external-cover.jpg',
            'alt_text' => 'External cover',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.content.media', [$account, $edition]))
            ->assertOk()
            ->assertSee('src="'.$storedMedia->url().'"', false)
            ->assertSee('src="'.$externalMedia->url().'"', false);
    }

    public function test_referenced_direction_and_workflow_cannot_be_deactivated(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id]);
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        FestivalCategory::factory()->for($edition)->for($direction)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.directions.toggle', [$account, $edition, $direction]))
            ->assertRedirect()
            ->assertSessionHasErrors('direction');
        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.directions.update', [$account, $edition, $direction]), [
                'name' => $direction->name,
                'is_active' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('direction');
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.workflows.toggle', [$account, $edition, $workflow]))
            ->assertRedirect()
            ->assertSessionHasErrors('workflow');

        $this->assertTrue($direction->refresh()->is_active);
        $this->assertTrue($workflow->refresh()->is_active);
    }

    public function test_fee_and_priced_requirement_forms_use_major_account_currency_amounts(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $account->update(['default_currency' => 'USD']);
        $edition->update(['currency' => 'UAH']);
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $step = FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create(['account_id' => $account->id]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]), [
            'festival_workflow_step_id' => $step->id,
            'kind' => 'participation',
            'name' => 'Roster fee',
            'amount' => '500.00',
            'pricing_mode' => 'roster',
            'included_members' => 2,
            'additional_member_amount' => '12.34',
            'due_policy' => 'fixed',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $fee = FestivalChargeDefinition::query()->where('festival_edition_id', $edition->id)->where('name', 'Roster fee')->firstOrFail();
        $this->assertSame(50000, $fee->amount_cents);
        $this->assertSame(1234, $fee->additional_member_amount_cents);
        $this->assertSame('USD', $fee->currency);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.charge-definitions.edit', [$account, $edition, $fee]))
            ->assertOk()
            ->assertSee('name="amount"', false)
            ->assertSee('value="500.00"', false)
            ->assertSee('name="additional_member_amount"', false)
            ->assertSee('value="12.34"', false)
            ->assertDontSee('name="amount_cents"', false)
            ->assertDontSee('name="additional_member_amount_cents"', false);

        $requirementPayload = [
            'festival_workflow_step_id' => $step->id,
            'type' => 'custom_document',
            'subject_scope' => 'entry',
            'input_type' => 'boolean',
            'name' => 'Flat priced answer',
            'pricing_mode' => 'flat_when_true',
            'price_amount' => '2900.05',
            'stage' => 'final',
            'max_size_kb' => 20480,
            'is_required' => 1,
            'is_active' => 1,
        ];
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), $requirementPayload)
            ->assertSessionHasNoErrors();
        $requirement = FestivalRequirementDefinition::query()->where('festival_edition_id', $edition->id)->where('name', 'Flat priced answer')->firstOrFail();
        $this->assertSame(290005, data_get($requirement->pricing, 'amount_cents'));
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.requirements.edit', [$account, $edition, $requirement]))
            ->assertOk()
            ->assertSee('name="price_amount"', false)
            ->assertSee('value="2900.05"', false)
            ->assertDontSee('name="price_amount_cents"', false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]), [
                'festival_workflow_step_id' => $step->id,
                'kind' => 'custom',
                'name' => 'Invalid precision fee',
                'amount' => '1.234',
                'pricing_mode' => 'fixed',
                'due_policy' => 'fixed',
            ])
            ->assertSessionHasErrors('amount');
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), [
                ...$requirementPayload,
                'name' => 'Invalid negative price',
                'price_amount' => '-0.01',
            ])
            ->assertSessionHasErrors('price_amount');
    }

    public function test_helper_selection_requirement_normalizes_its_input_scope_and_per_helper_pricing(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $step = FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create(['account_id' => $account->id]);
        $payload = [
            'festival_workflow_step_id' => $step->id,
            'type' => 'helper_selection',
            'subject_scope' => 'participant',
            'input_type' => 'boolean',
            'name' => 'Stage helpers',
            'pricing_mode' => 'flat_when_true',
            'price_amount' => '250.50',
            'allowed_extensions_text' => 'pdf',
            'allowed_mime_types_text' => 'application/pdf',
            'allowed_hosts_text' => 'example.com',
            'min_duration_seconds' => 10,
            'max_duration_seconds' => 20,
            'is_required' => 1,
            'is_active' => 1,
            'show_in_media_report' => 1,
        ];

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), $payload)
            ->assertSessionHasNoErrors();

        $requirement = FestivalRequirementDefinition::query()
            ->where('festival_edition_id', $edition->id)
            ->where('name', 'Stage helpers')
            ->sole();
        $this->assertSame('helper_selection', $requirement->type->value);
        $this->assertSame('helper_selection', $requirement->input_type->value);
        $this->assertSame('entry', $requirement->subject_scope->value);
        $this->assertSame(['mode' => 'per_unit', 'unit_amount_cents' => 25050], $requirement->pricing);
        $this->assertSame([], $requirement->options);
        $this->assertSame([], $requirement->allowed_extensions);
        $this->assertSame([], $requirement->allowed_mime_types);
        $this->assertSame([], data_get($requirement->validation, 'allowed_hosts'));
        $this->assertSame(20480, $requirement->max_size_kb);
        $this->assertNull($requirement->min_duration_seconds);
        $this->assertNull($requirement->max_duration_seconds);
        $this->assertFalse($requirement->show_in_media_report);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), [
                ...$payload,
                'type' => 'custom_document',
                'input_type' => 'helper_selection',
                'name' => 'Mismatched helper field',
            ])
            ->assertSessionHasErrors('input_type');
    }

    public function test_referenced_workflow_step_can_be_deactivated(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Referenced workflow');
        $step = $workflow->steps->firstWhere('code', 'technical_form');
        FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $step->id,
        ]);
        FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $step->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.workflow-steps.toggle', [$account, $edition, $workflow, $step]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($step->refresh()->is_active);
    }

    public function test_workflow_edit_manages_ordered_steps_and_protects_the_summary_invariant(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Managed workflow');
        $summary = $workflow->steps->firstWhere('type', FestivalWorkflowStepType::Summary);
        $workflowEditRoute = route('dashboard.accounts.festivals.workflows.edit', [$account, $edition, $workflow]);

        $this->actingAs($owner)
            ->get($workflowEditRoute)
            ->assertOk()
            ->assertSee(__('app.festival_workflow_steps_impact_copy'))
            ->assertSee(route('dashboard.accounts.festivals.workflow-steps.create', [$account, $edition, $workflow]), false)
            ->assertSee($summary->title);

        $payload = [
            'title' => 'Costume details',
            'type' => 'form',
            'sort_order' => 9999,
            'review_mode' => 'organizer',
            'review_effect' => 'none',
            'description' => 'Applicant costume details.',
            'is_active' => 1,
        ];
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.workflow-steps.store', [$account, $edition, $workflow]), $payload)
            ->assertRedirect($workflowEditRoute)
            ->assertSessionHasNoErrors();
        $step = $workflow->steps()->where('title', 'Costume details')->firstOrFail();
        $this->assertLessThan($summary->refresh()->sort_order, $step->sort_order);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.workflow-steps.update', [$account, $edition, $workflow, $step]), [
                ...$payload,
                'title' => 'Updated costume details',
            ])
            ->assertRedirect($workflowEditRoute)
            ->assertSessionHasNoErrors();
        $this->assertSame('Updated costume details', $step->refresh()->title);

        $previousStep = $workflow->steps()
            ->where('type', '!=', FestivalWorkflowStepType::Summary->value)
            ->where('sort_order', '<', $step->sort_order)
            ->reorder('sort_order', 'desc')
            ->orderByDesc('id')
            ->firstOrFail();
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.workflow-steps.move', [$account, $edition, $workflow, $step]), ['direction' => 'up'])
            ->assertSessionHasNoErrors();
        $this->assertLessThan($previousStep->refresh()->sort_order, $step->refresh()->sort_order);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.workflow-steps.toggle', [$account, $edition, $workflow, $step]))
            ->assertSessionHasNoErrors();
        $this->assertFalse($step->refresh()->is_active);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.workflow-steps.destroy', [$account, $edition, $workflow, $step]))
            ->assertSessionHasNoErrors();
        $this->assertModelMissing($step);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.workflow-steps.toggle', [$account, $edition, $workflow, $summary]))
            ->assertSessionHasErrors('festival_workflow_step');
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.workflow-steps.move', [$account, $edition, $workflow, $summary]), ['direction' => 'up'])
            ->assertSessionHasErrors('festival_workflow_step');
        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.workflow-steps.destroy', [$account, $edition, $workflow, $summary]))
            ->assertSessionHasErrors('festival_workflow_step');
        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.workflow-steps.update', [$account, $edition, $workflow, $summary]), [
                ...$payload,
                'title' => $summary->title,
            ])
            ->assertSessionHasErrors('type');
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.workflow-steps.store', [$account, $edition, $workflow]), [
                ...$payload,
                'title' => 'Second Summary',
                'type' => 'summary',
            ])
            ->assertSessionHasErrors('type');
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), [
                'festival_workflow_step_id' => $summary->id,
                'type' => 'custom_document',
                'subject_scope' => 'entry',
                'input_type' => 'short_text',
                'name' => 'Invalid Summary field',
                'pricing_mode' => 'none',
                'max_size_kb' => 20480,
                'is_required' => 1,
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('festival_workflow_step_id');
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]), [
                'festival_workflow_step_id' => $summary->id,
                'kind' => 'custom',
                'name' => 'Invalid Summary fee',
                'amount' => '10.00',
                'pricing_mode' => 'fixed',
                'due_policy' => 'fixed',
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('festival_workflow_step_id');

        $usedStep = $workflow->steps->firstWhere('code', 'application');
        FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $usedStep->id,
        ]);
        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.workflow-steps.destroy', [$account, $edition, $workflow, $usedStep]))
            ->assertSessionHasErrors('festival_workflow_step');
        $this->assertModelExists($usedStep);
    }

    public function test_direction_codes_are_collision_safe_stable_hidden_and_orderable(): void
    {
        [$account, $edition, $owner] = $this->festival();

        foreach (['Повітряне кільце', 'Повітряне кільце'] as $name) {
            $this->actingAs($owner)->post(route('dashboard.accounts.festivals.directions.store', [$account, $edition]), [
                'name' => $name,
            ])->assertRedirect();
        }

        $directions = FestivalDirection::query()->where('festival_edition_id', $edition->id)->orderBy('id')->get();
        $this->assertSame(['povitryane-kiltse', 'povitryane-kiltse-2'], $directions->pluck('code')->all());

        $first = $directions->firstOrFail();
        $second = $directions->last();
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.directions.update', [$account, $edition, $first]), [
            'name' => 'Новий напрямок',
            'is_active' => 1,
        ])->assertRedirect();
        $this->assertSame('povitryane-kiltse', $first->refresh()->code);

        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.directions.move', [$account, $edition, $second]), [
            'direction' => 'up',
        ])->assertRedirect();
        $this->assertLessThan($first->refresh()->sort_order, $second->refresh()->sort_order);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.directions', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('name="code"', false)
            ->assertDontSee('classification', false)
            ->assertDontSee('data-festival-edit-toggle', false)
            ->assertDontSee('action="'.route('dashboard.accounts.festivals.directions.store', [$account, $edition]).'"', false)
            ->assertSee(route('dashboard.accounts.festivals.directions.edit', [$account, $edition, $first]), false);
    }

    public function test_category_create_and_edit_pages_are_grouped_sanitized_and_timezone_aware(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $edition->forceFill(['timezone' => 'Europe/Kyiv'])->save();
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Pole Art']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]))
            ->assertOk()
            ->assertSee(route('dashboard.accounts.festivals.categories.create', [$account, $edition]), false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('data-studio-rules-editor', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.categories.create', [$account, $edition]))
            ->assertOk()
            ->assertSeeInOrder([
                __('app.festival_category_details'),
                __('app.festival_category_eligibility'),
                __('app.festival_category_application_counts'),
                __('app.festival_age_limits'),
                __('app.festival_category_performance'),
                __('app.festival_category_registration'),
                __('app.festival_category_requirements'),
            ])
            ->assertSeeInOrder([
                'name="competition_format"',
                'name="min_members"',
                'name="max_members"',
                'name="minimum_entries_to_run"',
                'name="maximum_accepted_entries"',
                'name="min_age"',
                'name="max_age"',
            ], false)
            ->assertSee('data-studio-rules-editor', false)
            ->assertSee('name="festival_direction_id"', false)
            ->assertSee('name="maximum_accepted_entries"', false)
            ->assertDontSee('name="workflow"', false)
            ->assertDontSee('name="option_ids', false);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.categories.store', [$account, $edition]), [
            'name' => 'Junior Pole Art',
            'festival_direction_id' => $direction->id,
            'min_members' => 1,
            'max_members' => 2,
            'min_age' => 8,
            'max_age' => 12,
            'min_duration_seconds' => 120,
            'max_duration_seconds' => 180,
            'maximum_accepted_entries' => 12,
            'registration_closes_at' => '2026-08-20T18:30',
            'requirements_html' => '<p><br></p>',
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]));

        $category = FestivalCategory::query()->where('festival_edition_id', $edition->id)->where('code', 'junior-pole-art')->firstOrFail();
        $this->assertNull($category->requirements_html);
        $this->assertSame(12, $category->maximum_accepted_entries);
        $this->assertSame('2026-08-20 15:30:00', $category->registration_closes_at->utc()->format('Y-m-d H:i:s'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]))
            ->assertOk()
            ->assertSee('value="2026-08-20T18:30"', false)
            ->assertSee('data-studio-rules-editor', false);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]))
            ->put(route('dashboard.accounts.festivals.categories.update', [$account, $edition, $category]), [
                'name' => 'Junior Pole Art',
                'festival_direction_id' => $direction->id,
                'min_members' => 3,
                'max_members' => 2,
            ])
            ->assertRedirect(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]))
            ->assertSessionHasErrors('max_members');
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]))
            ->assertSee('name="min_members" value="3"', false);
    }

    public function test_category_list_links_accepted_and_total_counts_and_capacity_cannot_drop_below_occupied_places(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'maximum_accepted_entries' => 3,
        ]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        foreach (range(1, 2) as $index) {
            FestivalEntry::factory()->for($category)->create([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'festival_portal_user_id' => $portalUser->id,
                'entry_name' => 'Accepted category entry '.$index,
                'status' => FestivalEntryStatus::Accepted,
                'accepted_at' => now(),
                'registration_completed_at' => now(),
            ]);
        }
        FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::ChangesPending,
            'accepted_at' => now(),
            'registration_completed_at' => now(),
        ]);
        FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::Draft,
        ]);

        $acceptedUrl = route('dashboard.accounts.festivals.applications', [$account, $edition, 'category' => $category->id, 'status' => FestivalEntryStatus::Accepted->value]);
        $totalUrl = route('dashboard.accounts.festivals.applications', [$account, $edition, 'category' => $category->id]);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]))
            ->assertOk()
            ->assertSee(htmlspecialchars($acceptedUrl, ENT_QUOTES), false)
            ->assertSee(htmlspecialchars($totalUrl, ENT_QUOTES), false)
            ->assertSeeInOrder(['>2</a>', '/', '>4</a>'], false)
            ->assertSee(__('app.festival_category_accepted_total'))
            ->assertSee(__('app.festival_category_capacity_full'))
            ->assertSee(__('app.festival_maximum_accepted_entries_value', ['maximum' => 3]));

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]))
            ->put(route('dashboard.accounts.festivals.categories.update', [$account, $edition, $category]), [
                'name' => $category->name,
                'festival_direction_id' => $category->festival_direction_id,
                'festival_workflow_id' => $category->festival_workflow_id,
                'competition_format' => $category->competition_format->value,
                'minimum_entries_to_run' => 1,
                'maximum_accepted_entries' => 2,
                'min_members' => $category->min_members,
                'max_members' => $category->max_members,
            ])
            ->assertSessionHasErrors('maximum_accepted_entries');
        $this->assertSame(3, $category->refresh()->maximum_accepted_entries);
    }

    public function test_category_and_direction_dependencies_are_tenant_scoped_and_manager_only(): void
    {
        [$account, $edition, $owner] = $this->festival();
        [$otherAccount, $otherEdition] = $this->festival();
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id]);
        $otherDirection = FestivalDirection::factory()->for($otherEdition)->create(['account_id' => $otherAccount->id]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.categories.store', [$account, $edition]), [
            'name' => 'Cross tenant category',
            'festival_direction_id' => $otherDirection->id,
            'min_members' => 1,
            'max_members' => 1,
        ])->assertSessionHasErrors('festival_direction_id');
        $this->assertDatabaseMissing('festival_categories', ['festival_edition_id' => $edition->id, 'name' => 'Cross tenant category']);

        $category = FestivalCategory::factory()->for($edition)->for($direction)->create(['account_id' => $account->id]);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.categories.edit', [$account, $otherEdition, $category]))
            ->assertNotFound();

        $finance = User::factory()->create();
        $account->users()->attach($finance->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageFestivalFinance->value],
        ]);
        $this->actingAs($finance)->get(route('dashboard.accounts.festivals.categories.create', [$account, $edition]))->assertForbidden();
        $this->actingAs($finance)->post(route('dashboard.accounts.festivals.directions.store', [$account, $edition]), ['name' => 'Forbidden'])->assertForbidden();
        $this->actingAs($finance)->patch(route('dashboard.accounts.festivals.categories.move', [$account, $edition, $category]), ['direction' => 'up'])->assertForbidden();
    }

    public function test_registration_field_choice_identifiers_remain_stable_and_hidden(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $step = $workflow->steps()->create([
            'account_id' => $account->id,
            'code' => 'technical-form',
            'type' => 'form',
            'title' => 'Технічна анкета',
            'sort_order' => 10,
            'review_mode' => 'organizer',
            'review_effect' => 'none',
        ]);
        $payload = [
            'festival_workflow_step_id' => $step->id,
            'type' => 'custom_document',
            'subject_scope' => 'entry',
            'input_type' => 'single_select',
            'name' => 'Варіант костюма',
            'pricing_mode' => 'option_prices',
            'stage' => 'final',
            'max_size_kb' => 20480,
            'options' => [
                ['label' => 'Стандарт', 'price' => '1.00'],
                ['label' => 'Стандарт', 'price' => '2.00'],
            ],
            'is_required' => 1,
            'is_active' => 1,
        ];

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), $payload)->assertRedirect();
        $field = FestivalRequirementDefinition::query()->where('festival_edition_id', $edition->id)->firstOrFail();
        $this->assertSame(['standart', 'standart-2'], collect($field->options)->pluck('value')->all());
        $this->assertSame(['standart' => 100, 'standart-2' => 200], data_get($field->pricing, 'prices'));
        $this->assertSame([['value' => 'standart', 'label' => 'Стандарт'], ['value' => 'standart-2', 'label' => 'Стандарт']], $field->options);

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.requirements.update', [$account, $edition, $field]), [
            ...$payload,
            'name' => 'Оновлений костюм',
            'options' => [
                ['original_value' => 'standart-2', 'label' => 'Преміум', 'price' => '3.00'],
                ['original_value' => 'standart', 'label' => 'Базовий', 'price' => '1.75'],
            ],
        ])->assertRedirect();
        $this->assertSame('variant-kostyuma', $field->refresh()->code);
        $this->assertSame(['standart-2', 'standart'], collect($field->options)->pluck('value')->all());
        $this->assertSame(['standart-2' => 300, 'standart' => 175], data_get($field->pricing, 'prices'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.requirements.edit', [$account, $edition, $field]))
            ->assertOk()
            ->assertSee('name="options[0][price]"', false)
            ->assertSee('value="3.00"', false)
            ->assertSee('value="1.75"', false)
            ->assertDontSee('price_cents', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('name="code"', false)
            ->assertDontSee('][value]', false)
            ->assertSee(__('app.festival_registration_fields'));
    }

    public function test_every_settings_resource_uses_filtered_paginated_lists_and_dedicated_forms(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Target direction']);
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Target workflow']);
        $step = FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create([
            'account_id' => $account->id,
            'title' => 'Target step',
            'type' => 'form',
            'review_mode' => 'automatic',
        ]);
        $category = FestivalCategory::factory()->for($edition)->for($direction)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
            'name' => 'Target category',
        ]);
        $requirement = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
            'festival_workflow_step_id' => $step->id,
            'name' => 'Target field',
        ]);
        $fee = FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
            'festival_workflow_step_id' => $step->id,
            'name' => 'Target fee',
        ]);
        $section = FestivalContentSection::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'key' => 'target-section',
            'title' => 'Target section',
            'body_html' => '<p>Target copy</p>',
        ]);
        $document = FestivalDocument::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'title' => 'Target document',
            'path' => 'festivals/target.pdf',
            'original_name' => 'target-file.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);
        $media = FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'kind' => 'image',
            'external_url' => 'https://example.test/target.jpg',
            'caption' => 'Target media',
            'is_cover' => true,
        ]);

        $resources = [
            [
                'index' => route('dashboard.accounts.festivals.settings.directions', [$account, $edition]),
                'view_key' => 'directions',
                'filters' => ['q', 'status'],
                'query' => ['q' => 'Target', 'status' => 'active'],
                'add' => route('dashboard.accounts.festivals.directions.create', [$account, $edition]),
                'edit' => route('dashboard.accounts.festivals.directions.edit', [$account, $edition, $direction]),
                'store' => route('dashboard.accounts.festivals.directions.store', [$account, $edition]),
                'update' => route('dashboard.accounts.festivals.directions.update', [$account, $edition, $direction]),
                'move' => route('dashboard.accounts.festivals.directions.move', [$account, $edition, $direction]),
            ],
            [
                'index' => route('dashboard.accounts.festivals.settings.categories', [$account, $edition]),
                'view_key' => 'categories',
                'filters' => ['q', 'status', 'direction', 'workflow'],
                'query' => ['q' => 'Target', 'status' => 'active', 'direction' => $direction->id, 'workflow' => $workflow->id],
                'add' => route('dashboard.accounts.festivals.categories.create', [$account, $edition]),
                'edit' => route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]),
                'store' => route('dashboard.accounts.festivals.categories.store', [$account, $edition]),
                'update' => route('dashboard.accounts.festivals.categories.update', [$account, $edition, $category]),
                'move' => route('dashboard.accounts.festivals.categories.move', [$account, $edition, $category]),
            ],
            [
                'index' => route('dashboard.accounts.festivals.settings.workflows', [$account, $edition]),
                'view_key' => 'workflows',
                'filters' => ['q', 'status'],
                'query' => ['q' => 'Target', 'status' => 'active'],
                'add' => route('dashboard.accounts.festivals.workflows.create', [$account, $edition]),
                'edit' => route('dashboard.accounts.festivals.workflows.edit', [$account, $edition, $workflow]),
                'store' => route('dashboard.accounts.festivals.workflows.store', [$account, $edition]),
                'update' => route('dashboard.accounts.festivals.workflows.update', [$account, $edition, $workflow]),
                'move' => route('dashboard.accounts.festivals.workflows.move', [$account, $edition, $workflow]),
            ],
            [
                'index' => route('dashboard.accounts.festivals.workflow-steps.index', [$account, $edition, $workflow]),
                'view_key' => 'steps',
                'filters' => ['q', 'status', 'type', 'review_mode'],
                'query' => ['q' => 'Target', 'status' => 'active', 'type' => 'form', 'review_mode' => 'automatic'],
                'add' => route('dashboard.accounts.festivals.workflow-steps.create', [$account, $edition, $workflow]),
                'edit' => route('dashboard.accounts.festivals.workflow-steps.edit', [$account, $edition, $workflow, $step]),
                'store' => route('dashboard.accounts.festivals.workflow-steps.store', [$account, $edition, $workflow]),
                'update' => route('dashboard.accounts.festivals.workflow-steps.update', [$account, $edition, $workflow, $step]),
                'move' => route('dashboard.accounts.festivals.workflow-steps.move', [$account, $edition, $workflow, $step]),
            ],
            [
                'index' => route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]),
                'view_key' => 'requirements',
                'filters' => ['q', 'status', 'category', 'workflow_step', 'input_type', 'scope'],
                'query' => ['q' => 'Target', 'status' => 'active', 'category' => $category->id, 'workflow_step' => $step->id, 'input_type' => 'file', 'scope' => 'entry'],
                'add' => route('dashboard.accounts.festivals.requirements.create', [$account, $edition]),
                'edit' => route('dashboard.accounts.festivals.requirements.edit', [$account, $edition, $requirement]),
                'store' => route('dashboard.accounts.festivals.requirements.store', [$account, $edition]),
                'update' => route('dashboard.accounts.festivals.requirements.update', [$account, $edition, $requirement]),
                'move' => route('dashboard.accounts.festivals.requirements.move', [$account, $edition, $requirement]),
            ],
            [
                'index' => route('dashboard.accounts.festivals.settings.fees', [$account, $edition]),
                'view_key' => 'fees',
                'filters' => ['q', 'status', 'category', 'workflow_step', 'kind'],
                'query' => ['q' => 'Target', 'status' => 'active', 'category' => $category->id, 'workflow_step' => $step->id, 'kind' => 'participation'],
                'add' => route('dashboard.accounts.festivals.charge-definitions.create', [$account, $edition]),
                'edit' => route('dashboard.accounts.festivals.charge-definitions.edit', [$account, $edition, $fee]),
                'store' => route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]),
                'update' => route('dashboard.accounts.festivals.charge-definitions.update', [$account, $edition, $fee]),
                'move' => route('dashboard.accounts.festivals.charge-definitions.move', [$account, $edition, $fee]),
            ],
            [
                'index' => route('dashboard.accounts.festivals.settings.content.sections', [$account, $edition]),
                'view_key' => 'sections',
                'filters' => ['q', 'status', 'visibility'],
                'query' => ['q' => 'Target', 'status' => 'active', 'visibility' => 'public'],
                'add' => route('dashboard.accounts.festivals.content.create', [$account, $edition]),
                'edit' => route('dashboard.accounts.festivals.content.edit', [$account, $edition, $section]),
                'store' => route('dashboard.accounts.festivals.content.store', [$account, $edition]),
                'update' => route('dashboard.accounts.festivals.content.update', [$account, $edition, $section]),
                'move' => route('dashboard.accounts.festivals.content.move', [$account, $edition, $section]),
            ],
            [
                'index' => route('dashboard.accounts.festivals.settings.content.documents', [$account, $edition]),
                'view_key' => 'documents',
                'filters' => ['q', 'status', 'kind', 'visibility'],
                'query' => ['q' => 'target-file', 'status' => 'active', 'kind' => 'document', 'visibility' => 'public'],
                'add' => route('dashboard.accounts.festivals.documents.create', [$account, $edition]),
                'edit' => route('dashboard.accounts.festivals.documents.edit', [$account, $edition, $document]),
                'store' => route('dashboard.accounts.festivals.documents.store', [$account, $edition]),
                'update' => route('dashboard.accounts.festivals.documents.update', [$account, $edition, $document]),
                'move' => route('dashboard.accounts.festivals.documents.move', [$account, $edition, $document]),
            ],
            [
                'index' => route('dashboard.accounts.festivals.settings.content.media', [$account, $edition]),
                'view_key' => 'mediaItems',
                'filters' => ['q', 'status', 'kind', 'cover'],
                'query' => ['q' => 'Target', 'status' => 'active', 'kind' => 'image', 'cover' => 'cover'],
                'add' => route('dashboard.accounts.festivals.media.create', [$account, $edition]),
                'edit' => route('dashboard.accounts.festivals.media.edit', [$account, $edition, $media]),
                'store' => route('dashboard.accounts.festivals.media.store', [$account, $edition]),
                'update' => route('dashboard.accounts.festivals.media.update', [$account, $edition, $media]),
                'move' => route('dashboard.accounts.festivals.media.move', [$account, $edition, $media]),
            ],
        ];

        foreach ($resources as $resource) {
            $list = $this->actingAs($owner)->get($resource['index']);
            $list->assertOk()
                ->assertViewHas($resource['view_key'], fn (mixed $items): bool => $items instanceof LengthAwarePaginator && $items->perPage() === 20)
                ->assertSee($resource['add'], false)
                ->assertSee($resource['edit'], false)
                ->assertDontSee('action="'.$resource['store'].'"', false)
                ->assertDontSee('data-festival-edit-toggle', false);

            foreach ($resource['filters'] as $filter) {
                $list->assertSee('name="'.$filter.'"', false);
            }

            $filtered = $this->actingAs($owner)->get($resource['index'].'?'.http_build_query($resource['query']));
            $filtered->assertOk()
                ->assertSee($resource['edit'], false)
                ->assertDontSee('action="'.$resource['move'].'"', false);

            $this->actingAs($owner)->get($resource['add'])
                ->assertOk()
                ->assertSee('action="'.$resource['store'].'"', false)
                ->assertSee($resource['index'], false);
            $this->actingAs($owner)->get($resource['edit'])
                ->assertOk()
                ->assertSee('action="'.$resource['update'].'"', false)
                ->assertSee($resource['index'], false);
        }
    }

    public function test_direction_pagination_retains_active_filters(): void
    {
        [$account, $edition, $owner] = $this->festival();

        foreach (range(1, 21) as $index) {
            FestivalDirection::factory()->for($edition)->create([
                'account_id' => $account->id,
                'name' => 'Searchable direction '.$index,
            ]);
        }

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.settings.directions', [
            $account,
            $edition,
            'q' => 'Searchable',
            'status' => 'active',
        ]));

        $response->assertOk();
        $directions = $response->viewData('directions');
        $this->assertInstanceOf(LengthAwarePaginator::class, $directions);
        $this->assertStringContainsString('page=2', (string) $directions->nextPageUrl());
        $this->assertStringContainsString('q=Searchable', (string) $directions->nextPageUrl());
        $this->assertStringContainsString('status=active', (string) $directions->nextPageUrl());
    }

    public function test_registration_fields_can_be_reordered_within_a_workflow_step_filter(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $firstStep = FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create(['account_id' => $account->id]);
        $secondStep = FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create(['account_id' => $account->id]);
        $firstField = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $firstStep->id,
            'name' => 'First filtered field',
            'sort_order' => 10,
        ]);
        $firstHiddenField = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $secondStep->id,
            'name' => 'First hidden field',
            'sort_order' => 20,
        ]);
        $secondField = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $firstStep->id,
            'name' => 'Second filtered field',
            'sort_order' => 30,
        ]);
        $secondHiddenField = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $secondStep->id,
            'name' => 'Second hidden field',
            'sort_order' => 40,
        ]);
        $indexRoute = route('dashboard.accounts.festivals.settings.requirements', [
            $account,
            $edition,
            'workflow_step' => $firstStep->id,
        ]);
        $moveRoute = route('dashboard.accounts.festivals.requirements.move', [$account, $edition, $firstField]);

        $this->actingAs($owner)
            ->get($indexRoute)
            ->assertOk()
            ->assertSee('action="'.$moveRoute.'"', false)
            ->assertSee('name="ordering_scope" value="workflow_step"', false);

        $this->actingAs($owner)
            ->get($indexRoute.'&q=First')
            ->assertOk()
            ->assertDontSee('action="'.$moveRoute.'"', false);

        $this->actingAs($owner)
            ->from($indexRoute)
            ->patch($moveRoute, ['direction' => 'down', 'ordering_scope' => 'workflow_step'])
            ->assertRedirect($indexRoute)
            ->assertSessionHas('status', __('app.festival_order_saved'));

        $this->assertSame([
            $secondField->id,
            $firstHiddenField->id,
            $firstField->id,
            $secondHiddenField->id,
        ], FestivalRequirementDefinition::query()
            ->where('festival_edition_id', $edition->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all());

        $this->actingAs($owner)
            ->patch($moveRoute, ['direction' => 'up', 'ordering_scope' => 'unsupported'])
            ->assertSessionHasErrors('ordering_scope');
    }

    public function test_unused_registration_field_has_confirmed_permanent_delete_and_used_field_is_protected(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id]);
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $step = FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create(['account_id' => $account->id]);
        $category = FestivalCategory::factory()->for($edition)->for($direction)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
        ]);
        $unusedField = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $step->id,
            'name' => 'Unused field',
        ]);
        $usedField = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $step->id,
            'name' => 'Used field',
        ]);
        $entry = FestivalEntry::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_category_id' => $category->id,
        ]);
        FestivalEntryRequirement::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'festival_requirement_definition_id' => $usedField->id,
        ]);
        $indexRoute = route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]);
        $unusedDeleteRoute = route('dashboard.accounts.festivals.requirements.destroy', [$account, $edition, $unusedField]);
        $usedDeleteRoute = route('dashboard.accounts.festivals.requirements.destroy', [$account, $edition, $usedField]);

        $this->actingAs($owner)
            ->get($indexRoute)
            ->assertOk()
            ->assertSee('action="'.$unusedDeleteRoute.'"', false)
            ->assertSee('data-confirm-delete', false)
            ->assertSee('data-confirm-title="'.__('app.festival_delete_registration_field_confirm_title').'"', false)
            ->assertDontSee('action="'.$usedDeleteRoute.'"', false);

        $this->actingAs($owner)
            ->delete($usedDeleteRoute)
            ->assertRedirect($indexRoute)
            ->assertSessionHasErrors('festival_requirement');
        $this->assertModelExists($usedField);

        $otherEdition = FestivalEdition::factory()->published()->for($edition->series)->create([
            'account_id' => $account->id,
        ]);
        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.requirements.destroy', [$account, $otherEdition, $unusedField]))
            ->assertNotFound();
        $this->assertModelExists($unusedField);

        $this->actingAs($owner)
            ->delete($unusedDeleteRoute)
            ->assertRedirect($indexRoute)
            ->assertSessionHas('status', __('app.festival_registration_field_deleted'));
        $this->assertModelMissing($unusedField);
    }

    public function test_dedicated_complex_forms_render_field_level_validation_errors(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create(['account_id' => $account->id]);

        $forms = [
            [
                'create' => route('dashboard.accounts.festivals.workflow-steps.create', [$account, $edition, $workflow]),
                'store' => route('dashboard.accounts.festivals.workflow-steps.store', [$account, $edition, $workflow]),
                'field' => 'title',
            ],
            [
                'create' => route('dashboard.accounts.festivals.requirements.create', [$account, $edition]),
                'store' => route('dashboard.accounts.festivals.requirements.store', [$account, $edition]),
                'field' => 'name',
            ],
            [
                'create' => route('dashboard.accounts.festivals.charge-definitions.create', [$account, $edition]),
                'store' => route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]),
                'field' => 'name',
            ],
        ];

        foreach ($forms as $form) {
            $this->actingAs($owner)
                ->from($form['create'])
                ->followingRedirects()
                ->post($form['store'], [])
                ->assertOk()
                ->assertSee('data-field-error="'.$form['field'].'"', false);
        }
    }

    public function test_workflow_step_pages_enforce_nested_ownership(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $otherWorkflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $otherStep = FestivalWorkflowStep::factory()->for($otherWorkflow, 'workflow')->create(['account_id' => $account->id]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.workflow-steps.edit', [$account, $edition, $workflow, $otherStep]))
            ->assertNotFound();
    }

    public function test_workflow_step_completion_notifications_are_tabbed_validated_and_merge_config(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Notification workflow');
        $step = $workflow->steps->firstWhere('code', 'application');
        $summary = $workflow->steps->firstWhere('type', FestivalWorkflowStepType::Summary);
        $step->forceFill(['config' => ['unrelated' => ['preserved' => true]]])->save();
        $editRoute = route('dashboard.accounts.festivals.workflow-steps.edit', [
            $account,
            $edition,
            $workflow,
            $step,
            'tab' => 'completion-notifications',
        ]);
        $updateRoute = route('dashboard.accounts.festivals.workflow-steps.completion-notifications.update', [$account, $edition, $workflow, $step]);

        $this->actingAs($owner)
            ->get($editRoute)
            ->assertOk()
            ->assertSee(__('app.festival_workflow_step_tab_details'))
            ->assertSee(__('app.festival_workflow_step_tab_completion_notifications'))
            ->assertSee('name="completion_notifications[uk][email]"', false)
            ->assertSee('name="completion_notifications[en][telegram]"', false)
            ->assertSee('%name%', false)
            ->assertSee('%category%', false);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.workflow-steps.create', [$account, $edition, $workflow]))
            ->assertOk()
            ->assertDontSee(__('app.festival_workflow_step_tab_completion_notifications'));
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.workflow-steps.edit', [$account, $edition, $workflow, $summary, 'tab' => 'completion-notifications']))
            ->assertOk()
            ->assertSee('name="completion_notifications[uk][email]"', false);

        $payload = [
            'completion_notifications' => [
                'uk' => [
                    'email' => '  Вітаємо, %name%! Категорія: %category%.  ',
                    'sms' => '',
                    'telegram' => 'Telegram для %name%',
                ],
                'en' => [
                    'email' => 'Welcome, %name%!',
                    'sms' => 'Approved in %category%',
                    'telegram' => '',
                ],
            ],
        ];
        $this->actingAs($owner)
            ->put($updateRoute, $payload)
            ->assertRedirect($editRoute)
            ->assertSessionHasNoErrors();

        $this->assertSame([
            'unrelated' => ['preserved' => true],
            'completion_notifications' => [
                'uk' => [
                    'email' => 'Вітаємо, %name%! Категорія: %category%.',
                    'telegram' => 'Telegram для %name%',
                ],
                'en' => [
                    'email' => 'Welcome, %name%!',
                    'sms' => 'Approved in %category%',
                ],
            ],
        ], $step->refresh()->config);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.workflow-steps.update', [$account, $edition, $workflow, $step]), [
                'title' => 'Application details updated',
                'type' => $step->type->value,
                'sort_order' => $step->sort_order,
                'review_mode' => $step->review_mode->value,
                'review_effect' => $step->review_effect->value,
                'description' => $step->description,
                'opens_at' => null,
                'due_at' => null,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('Welcome, %name%!', data_get($step->refresh()->config, 'completion_notifications.en.email'));

        $this->actingAs($owner)
            ->from($editRoute)
            ->put($updateRoute, [
                ...$payload,
                'completion_notifications' => [
                    ...$payload['completion_notifications'],
                    'en' => [
                        ...$payload['completion_notifications']['en'],
                        'email' => 'Unknown %studio%',
                    ],
                ],
            ])
            ->assertRedirect($editRoute)
            ->assertSessionHasErrors('completion_notifications.en.email');
        $this->actingAs($owner)
            ->from($editRoute)
            ->put($updateRoute, [
                ...$payload,
                'completion_notifications' => [
                    ...$payload['completion_notifications'],
                    'uk' => [
                        ...$payload['completion_notifications']['uk'],
                        'sms' => str_repeat('x', 1001),
                    ],
                ],
            ])
            ->assertRedirect($editRoute)
            ->assertSessionHasErrors('completion_notifications.uk.sms');

        $blank = [
            'completion_notifications' => [
                'uk' => ['email' => '', 'sms' => '', 'telegram' => ''],
                'en' => ['email' => '', 'sms' => '', 'telegram' => ''],
            ],
        ];
        $this->actingAs($owner)->put($updateRoute, $blank)->assertSessionHasNoErrors();
        $this->assertSame(['unrelated' => ['preserved' => true]], $step->refresh()->config);

        $summaryUpdateRoute = route('dashboard.accounts.festivals.workflow-steps.completion-notifications.update', [$account, $edition, $workflow, $summary]);
        $this->actingAs($owner)->put($summaryUpdateRoute, [
            'completion_notifications' => [
                'uk' => ['email' => 'Фінал для %name%', 'sms' => '', 'telegram' => ''],
                'en' => ['email' => '', 'sms' => '', 'telegram' => ''],
            ],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Фінал для %name%', data_get($summary->refresh()->config, 'completion_notifications.uk.email'));

        $this->actingAs(User::factory()->create())
            ->put($updateRoute, $blank)
            ->assertForbidden();

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.workflow-steps.completion-notifications.update', [$account, $edition, $workflow, FestivalWorkflowStep::factory()->for(
                FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]),
                'workflow',
            )->create(['account_id' => $account->id])]), $blank)
            ->assertNotFound();
    }

    public function test_document_replacement_keeps_the_record_and_removes_the_previous_file(): void
    {
        Storage::fake('local');
        [$account, $edition, $owner] = $this->festival();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.documents.store', [$account, $edition]), [
            'title' => 'Rules',
            'kind' => 'rules',
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('old-rules.pdf', 10, 'application/pdf'),
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.content.documents', [$account, $edition]));

        $document = FestivalDocument::query()->where('festival_edition_id', $edition->id)->firstOrFail();
        $oldPath = $document->path;
        Storage::disk('local')->assertExists($oldPath);

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.documents.update', [$account, $edition, $document]), [
            'title' => 'Updated rules',
            'kind' => 'rules',
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('new-rules.pdf', 12, 'application/pdf'),
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.content.documents', [$account, $edition]));

        $document->refresh();
        $this->assertSame('Updated rules', $document->title);
        $this->assertNotSame($oldPath, $document->path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($document->path);
    }

    /** @return array{Account, FestivalEdition, User} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'uk']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        return [$account, $edition, $owner];
    }
}
