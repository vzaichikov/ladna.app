<?php

namespace Tests\Feature;

use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Actions\Festivals\StoreFestivalSubmission;
use App\Actions\Festivals\SubmitFestivalEntryStep;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalRequirementType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Event;
use App\Models\FestivalActivityLog;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalParticipant;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Festivals\MediaDurationProbe;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class FestivalRegistrationWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_entry_creation_uses_live_category_and_typed_fields_while_preserving_charge_facts(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'min_members' => 2,
            'max_members' => 3,
            'min_age' => 12,
            'max_age' => 17,
        ]);
        $participants = collect([
            FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id, 'date_of_birth' => $edition->age_reference_date->copy()->subYears(13)]),
            FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id, 'date_of_birth' => $edition->age_reference_date->copy()->subYears(15)]),
        ]);
        $requirement = FestivalRequirementDefinition::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Qualification video', 'type' => 'qualification_video']);
        $chargeDefinition = FestivalChargeDefinition::factory()->for($edition)->create(['account_id' => $account->id, 'amount_cents' => 50000]);
        $entry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id]);
        $entry->participants()->sync($participants->values()->mapWithKeys(fn ($participant, $index): array => [$participant->id => ['account_id' => $account->id, 'sort_order' => $index]])->all());

        $initialized = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $category->update(['name' => 'Changed category', 'min_members' => 3]);
        $requirement->update(['name' => 'Changed requirement']);
        $chargeDefinition->update(['amount_cents' => 90000]);
        $initialized->refresh()->load(['category', 'participants', 'requirements.definition', 'charges']);

        $this->assertSame(FestivalEntryStatus::Draft, $initialized->status);
        $this->assertSame('Changed category', $initialized->category->name);
        $this->assertSame(3, $initialized->category->min_members);
        $this->assertSame([13, 15], $initialized->participants->map(fn (FestivalParticipant $participant): int => $participant->ageOn($edition->age_reference_date))->sort()->values()->all());
        $this->assertSame('Changed requirement', $initialized->requirements->first()->definition->name);
        $this->assertSame(50000, $initialized->charges->first()->amount_cents);
    }

    public function test_category_rule_edits_apply_to_an_existing_entry(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'min_members' => 1,
            'max_members' => 2,
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'date_of_birth' => $edition->age_reference_date->copy()->subYears(15),
        ]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        $entry->participants()->sync([$participant->id => [
            'account_id' => $account->id,
            'sort_order' => 0,
        ]]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $category->update(['min_members' => 2]);

        $this->expectException(ValidationException::class);
        app(SubmitFestivalEntryStep::class)->execute($entry, $entry->steps->first());
    }

    public function test_invalid_member_count_and_age_are_rejected(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'min_members' => 2, 'max_members' => 2, 'min_age' => 18]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id, 'date_of_birth' => $edition->age_reference_date->copy()->subYears(12)]);
        $entry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id]);
        $entry->participants()->sync([$participant->id => ['account_id' => $account->id, 'sort_order' => 0]]);

        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);

        $this->expectException(ValidationException::class);
        app(SubmitFestivalEntryStep::class)->execute($entry, $entry->steps->first());
    }

    public function test_applicant_category_cards_are_grouped_by_direction_and_show_live_requirements(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $firstDirection = FestivalDirection::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Aerial',
            'code' => 'aerial-one',
            'sort_order' => 10,
        ]);
        $secondDirection = FestivalDirection::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Aerial',
            'code' => 'aerial-two',
            'sort_order' => 20,
        ]);
        $category = FestivalCategory::factory()->for($edition)->for($firstDirection)->create([
            'account_id' => $account->id,
            'name' => 'Solo Hoop',
            'requirements_html' => '<p>Bring a certified hoop.</p>',
            'registration_closes_at' => CarbonImmutable::parse('2026-09-15 12:30', 'Europe/Kyiv')->utc(),
            'min_members' => 1,
            'max_members' => 1,
            'min_age' => 12,
            'max_age' => 18,
        ]);
        $fullCategory = FestivalCategory::factory()->for($edition)->for($secondDirection)->create([
            'account_id' => $account->id,
            'name' => 'Solo Silks',
            'requirements_html' => null,
            'maximum_accepted_entries' => 1,
        ]);
        FestivalEntry::factory()->for($fullCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::Accepted,
            'accepted_at' => now(),
            'registration_completed_at' => now(),
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'date_of_birth' => $edition->age_reference_date->copy()->subYears(15),
        ]);

        $createPage = $this->actingAs($portalUser, 'festival')->get(route('festival.portal.entries.create', [$account->slug, $edition->slug]));
        $createPage->assertOk()
            ->assertSee('type="radio" name="festival_category_id"', false)
            ->assertDontSee('<select name="festival_category_id"', false)
            ->assertSee('Solo Hoop')
            ->assertSee('Bring a certified hoop.', false)
            ->assertSee(__('app.festival_category_deadline_value', ['date' => '15.09.2026 12:30', 'timezone' => 'Europe/Kyiv']))
            ->assertSee(__('app.festival_category_requirements_none'))
            ->assertSee(__('app.festival_category_full'));
        $this->assertMatchesRegularExpression('/<input[^>]*value="'.$fullCategory->id.'"[^>]*disabled[^>]*>/', $createPage->getContent());
        $this->assertSame(2, substr_count($createPage->getContent(), '<legend class="text-base font-semibold text-slate-950">Aerial</legend>'));

        $this->actingAs($portalUser, 'festival')
            ->from(route('festival.portal.entries.create', [$account->slug, $edition->slug]))
            ->post(route('festival.portal.entries.store', [$account->slug, $edition->slug]), [
                'festival_category_id' => $fullCategory->id,
                'participant_ids' => [$participant->id],
                'entry_name' => 'Forged full category act',
            ])
            ->assertSessionHasErrors('festival_category_id');
        $this->assertFalse(FestivalEntry::query()->where('entry_name', 'Forged full category act')->exists());

        $this->actingAs($portalUser, 'festival')->post(route('festival.portal.entries.store', [$account->slug, $edition->slug]), [
            'festival_category_id' => $category->id,
            'participant_ids' => [$participant->id],
            'entry_name' => 'Live category act',
        ])->assertRedirect();
        $entry = FestivalEntry::query()->where('festival_portal_user_id', $portalUser->id)->where('entry_name', 'Live category act')->firstOrFail();
        $fullCategoryDraft = FestivalEntry::factory()->for($fullCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Existing full category draft',
            'status' => FestivalEntryStatus::Draft,
        ]);
        $fullCategoryDraft->participants()->sync([$participant->id => [
            'account_id' => $account->id,
            'sort_order' => 0,
        ]]);
        $firstDirection->update(['name' => 'Updated Aerial']);
        $category->update(['name' => 'Updated Solo Hoop', 'requirements_html' => '<p>Current organizer conditions.</p>']);

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entries.edit', [$account->slug, $entry]))
            ->assertOk()
            ->assertSee('Updated Aerial')
            ->assertSee('Updated Solo Hoop')
            ->assertSee('Current organizer conditions.', false)
            ->assertSee(__('app.festival_category_deadline_value', ['date' => '15.09.2026 12:30', 'timezone' => 'Europe/Kyiv']))
            ->assertDontSee('Bring a certified hoop.')
            ->assertDontSee('type="hidden" name="festival_category_id"', false)
            ->assertSee('type="radio" name="festival_category_id"', false)
            ->assertSee(__('app.festival_category_change_available_copy'));

        $currentDraftPage = $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entries.edit', [$account->slug, $fullCategoryDraft]));
        $currentDraftPage->assertOk();
        $this->assertMatchesRegularExpression('/<input[^>]*value="'.$fullCategory->id.'"[^>]*checked(?![^>]*disabled)[^>]*>/', $currentDraftPage->getContent());

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entries.show', [$account->slug, $entry]))
            ->assertOk()
            ->assertSee('Current organizer conditions.', false)
            ->assertSee(__('app.festival_category_deadline_value', ['date' => '15.09.2026 12:30', 'timezone' => 'Europe/Kyiv']))
            ->assertDontSee('Bring a certified hoop.');
    }

    public function test_applicant_may_change_a_draft_category_until_any_payment_starts(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Applicant category change');
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id]);
        $source = FestivalCategory::factory()->for($edition)->for($direction)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
            'name' => 'Draft source',
            'min_members' => 1,
            'max_members' => 2,
        ]);
        $target = FestivalCategory::factory()->for($edition)->for($direction)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
            'name' => 'Draft target',
            'min_members' => 1,
            'max_members' => 2,
        ]);
        $otherWorkflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Other workflow');
        $otherCategory = FestivalCategory::factory()->for($edition)->for($direction)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $otherWorkflow->id,
            'name' => 'Other workflow category',
        ]);
        FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $workflow->steps->first()->id,
            'amount_cents' => 25000,
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($source)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Draft category entry',
            'status' => FestivalEntryStatus::Draft,
        ]);
        $entry->participants()->sync([$participant->id => ['account_id' => $account->id, 'sort_order' => 0]]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $stepIds = $entry->steps()->pluck('id')->all();

        $edit = $this->actingAs($portalUser, 'festival')->get(route('festival.portal.entries.edit', [$account->slug, $entry]));
        $edit->assertOk()
            ->assertSee($source->name)
            ->assertSee($target->name)
            ->assertDontSee($otherCategory->name)
            ->assertSee('type="radio" name="festival_category_id"', false);

        $payload = [
            'festival_category_id' => $target->id,
            'participant_ids' => [$participant->id],
            'entry_name' => $entry->entry_name,
        ];
        $this->actingAs($portalUser, 'festival')
            ->put(route('festival.portal.entries.update', [$account->slug, $entry]), $payload)
            ->assertRedirect(route('festival.portal.entries.show', [$account->slug, $entry]));

        $this->assertSame($target->id, $entry->refresh()->festival_category_id);
        $this->assertSame($stepIds, $entry->steps()->pluck('id')->all());
        $this->assertDatabaseHas('festival_activity_logs', [
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'actor_portal_user_id' => $portalUser->id,
            'action' => 'entry.category_reassigned',
        ]);

        $payload['festival_category_id'] = $otherCategory->id;
        $this->actingAs($portalUser, 'festival')
            ->from(route('festival.portal.entries.edit', [$account->slug, $entry]))
            ->put(route('festival.portal.entries.update', [$account->slug, $entry]), $payload)
            ->assertSessionHasErrors('festival_category_id');
        $this->assertSame($target->id, $entry->refresh()->festival_category_id);

        $charge = $entry->charges()->firstOrFail();
        $attempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'provider' => 'monopay',
            'order_id' => 'FCHP-APPLICANT-CATEGORY',
            'amount_cents' => $charge->amount_cents,
            'currency' => $charge->currency,
            'status' => 'expired',
            'expires_at' => now()->subMinute(),
        ]);
        $attempt->allocations()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'amount_cents' => $charge->amount_cents,
            'currency' => $charge->currency,
        ]);
        $payload['festival_category_id'] = $source->id;
        $this->actingAs($portalUser, 'festival')
            ->from(route('festival.portal.entries.edit', [$account->slug, $entry]))
            ->put(route('festival.portal.entries.update', [$account->slug, $entry]), $payload)
            ->assertSessionHasErrors('festival_category_id');
        $this->assertSame($target->id, $entry->refresh()->festival_category_id);
        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entries.edit', [$account->slug, $entry]))
            ->assertOk()
            ->assertSee('type="hidden" name="festival_category_id"', false)
            ->assertDontSee('type="radio" name="festival_category_id"', false);
    }

    public function test_applicant_category_change_allows_an_automatic_zero_charge_but_not_a_manual_positive_payment_or_non_draft_entry(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $workflow = app(ProvisionFestivalWorkflow::class)->execute($edition, 'Applicant payment lock');
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id]);
        $source = FestivalCategory::factory()->for($edition)->for($direction)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
            'name' => 'Zero charge source',
        ]);
        $target = FestivalCategory::factory()->for($edition)->for($direction)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
            'name' => 'Zero charge target',
        ]);
        FestivalChargeDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $workflow->steps->first()->id,
            'amount_cents' => 0,
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($source)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Zero charge entry',
        ]);
        $entry->participants()->sync([$participant->id => ['account_id' => $account->id, 'sort_order' => 0]]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $charge = $entry->charges()->firstOrFail();
        $this->assertSame(0, $charge->amount_cents);
        $this->assertSame(FestivalChargeStatus::Paid, $charge->status);
        $payload = [
            'festival_category_id' => $target->id,
            'participant_ids' => [$participant->id],
            'entry_name' => $entry->entry_name,
        ];

        $this->actingAs($portalUser, 'festival')
            ->put(route('festival.portal.entries.update', [$account->slug, $entry]), $payload)
            ->assertRedirect(route('festival.portal.entries.show', [$account->slug, $entry]));
        $this->assertSame($target->id, $entry->refresh()->festival_category_id);

        $charge->forceFill([
            'amount_cents' => 50000,
            'status' => FestivalChargeStatus::Paid,
            'paid_at' => now(),
        ])->save();
        $payload['festival_category_id'] = $source->id;
        $this->actingAs($portalUser, 'festival')
            ->from(route('festival.portal.entries.edit', [$account->slug, $entry]))
            ->put(route('festival.portal.entries.update', [$account->slug, $entry]), $payload)
            ->assertSessionHasErrors('festival_category_id');
        $this->assertSame($target->id, $entry->refresh()->festival_category_id);

        $submittedEntry = FestivalEntry::factory()->for($source)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Submitted category entry',
            'status' => FestivalEntryStatus::Submitted,
        ]);
        $submittedEntry->participants()->sync([$participant->id => ['account_id' => $account->id, 'sort_order' => 0]]);
        $submittedEntry = app(InitializeFestivalEntryWorkflow::class)->execute($submittedEntry);
        $submittedEntry->steps->first()->update(['status' => FestivalEntryStepStatus::ChangesRequested]);
        $submittedPayload = $payload;
        $submittedPayload['entry_name'] = $submittedEntry->entry_name;
        $submittedPayload['festival_category_id'] = $target->id;

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.entries.edit', [$account->slug, $submittedEntry]))
            ->assertOk()
            ->assertSee('type="hidden" name="festival_category_id"', false)
            ->assertDontSee('type="radio" name="festival_category_id"', false);
        $this->actingAs($portalUser, 'festival')
            ->from(route('festival.portal.entries.edit', [$account->slug, $submittedEntry]))
            ->put(route('festival.portal.entries.update', [$account->slug, $submittedEntry]), $submittedPayload)
            ->assertSessionHasErrors('festival_category_id');
        $this->assertSame($source->id, $submittedEntry->refresh()->festival_category_id);
    }

    public function test_private_submission_replacement_keeps_only_current_file_and_enforces_portal_tenancy(): void
    {
        Storage::fake('local');
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        FestivalRequirementDefinition::factory()->for($edition)->create(['account_id' => $account->id, 'type' => 'custom_document', 'stage' => 'qualification', 'allowed_extensions' => ['png'], 'allowed_mime_types' => ['image/png']]);
        $entry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id]);
        $entry->participants()->sync([$participant->id => ['account_id' => $account->id, 'sort_order' => 0]]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $requirement = $entry->requirements->first();

        $first = app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->image('proof.png'));
        $firstPath = $first->path;
        $second = app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->image('replacement.png'));

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $requirement->submissions()->get());
        $this->assertSame(FestivalRequirementStatus::Submitted, $requirement->refresh()->status);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($second->path);

        $this->actingAs($portalUser, 'festival')->get(route('festival.portal.submissions.download', [$account->slug, $second]))->assertOk();
        $otherPortalUser = FestivalPortalUser::factory()->for($account)->create();
        $this->actingAs($otherPortalUser, 'festival')->get(route('festival.portal.submissions.download', [$account->slug, $second]))->assertNotFound();
    }

    public function test_music_submission_uses_category_duration_bounds_and_accepts_exact_boundaries(): void
    {
        Storage::fake('local');
        [, $portalUser, $requirement] = $this->fileRequirement(
            ['min_duration_seconds' => 150, 'max_duration_seconds' => 195],
        );

        foreach ([149, 196] as $duration) {
            $this->mockMediaDuration($duration);

            try {
                app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->create('music.mp3'));
                $this->fail("Music lasting {$duration} seconds should have been rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }

        $this->assertCount(0, $requirement->submissions()->get());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->mockMediaDuration(150);
        $minimum = app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->create('minimum.mp3'));
        $this->assertSame(150, $minimum->duration_seconds);

        $this->mockMediaDuration(180);
        $middle = app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->create('middle.mp3'));
        $this->assertSame($minimum->id, $middle->id);
        $this->assertSame(180, $middle->duration_seconds);
        Storage::disk('local')->assertMissing($minimum->path);
        Storage::disk('local')->assertExists($middle->path);

        $this->mockMediaDuration(195);
        $maximum = app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->create('maximum.mp3'));
        $this->assertSame($middle->id, $maximum->id);
        $this->assertSame(195, $maximum->duration_seconds);
        Storage::disk('local')->assertMissing($middle->path);
        Storage::disk('local')->assertExists($maximum->path);
    }

    public function test_music_submission_uses_current_category_duration_after_an_edit(): void
    {
        Storage::fake('local');
        [$category, $portalUser, $requirement] = $this->fileRequirement(
            ['min_duration_seconds' => 150, 'max_duration_seconds' => 195],
        );
        $category->update(['max_duration_seconds' => 210]);
        $this->mockMediaDuration(200);

        $submission = app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->create('music.mp3'));

        $this->assertSame(200, $submission->duration_seconds);
        Storage::disk('local')->assertExists($submission->path);
    }

    public function test_music_definition_duration_bounds_override_category_bounds_independently(): void
    {
        Storage::fake('local');
        [, $minimumPortalUser, $minimumRequirement] = $this->fileRequirement(
            ['min_duration_seconds' => 150, 'max_duration_seconds' => 195],
            ['min_duration_seconds' => 160],
        );
        $this->mockMediaDuration(155);

        try {
            app(StoreFestivalSubmission::class)->execute($minimumRequirement, $minimumPortalUser, UploadedFile::fake()->create('minimum.mp3'));
            $this->fail('The explicit Music minimum should override the category minimum.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        [, $maximumPortalUser, $maximumRequirement] = $this->fileRequirement(
            ['min_duration_seconds' => 150, 'max_duration_seconds' => 195],
            ['max_duration_seconds' => 180],
        );
        $this->mockMediaDuration(185);

        try {
            app(StoreFestivalSubmission::class)->execute($maximumRequirement, $maximumPortalUser, UploadedFile::fake()->create('maximum.mp3'));
            $this->fail('The explicit Music maximum should override the category maximum.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    public function test_music_submission_rejects_unreadable_duration_when_category_has_bounds(): void
    {
        Storage::fake('local');
        [, $portalUser, $requirement] = $this->fileRequirement(
            ['min_duration_seconds' => 150, 'max_duration_seconds' => 195],
        );
        $this->mockMediaDuration(new RuntimeException('Unreadable media.'));

        try {
            app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->create('music.mp3'));
            $this->fail('Unreadable Music duration should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertCount(0, $requirement->submissions()->get());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_non_music_submission_does_not_inherit_category_duration_bounds(): void
    {
        Storage::fake('local');
        [, $portalUser, $requirement] = $this->fileRequirement(
            ['min_duration_seconds' => 150, 'max_duration_seconds' => 195],
            ['type' => FestivalRequirementType::CustomDocument->value],
        );
        $this->mock(MediaDurationProbe::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('seconds');
        });

        $submission = app(StoreFestivalSubmission::class)->execute($requirement, $portalUser, UploadedFile::fake()->create('document.pdf'));

        $this->assertNull($submission->duration_seconds);
        Storage::disk('local')->assertExists($submission->path);
    }

    public function test_payment_callback_is_idempotent_and_rejects_wrong_amount(): void
    {
        Queue::fake();
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'festival_portal_user_id' => $portalUser->id]);
        $charge = $entry->charges()->create(['account_id' => $account->id, 'code' => 'FCH-TEST', 'kind' => 'participation', 'name' => 'Fee', 'amount_cents' => 10000, 'currency' => 'UAH']);
        $attempt = FestivalPaymentAttempt::query()->create(['account_id' => $account->id, 'festival_charge_id' => $charge->id, 'provider' => 'monopay', 'order_id' => 'FCHP-TEST', 'amount_cents' => 10000, 'currency' => 'UAH', 'expires_at' => now()->addMinutes(30)]);
        $attempt->allocations()->create(['account_id' => $account->id, 'festival_charge_id' => $charge->id, 'amount_cents' => 10000, 'currency' => 'UAH']);
        $callback = new PaymentCallbackResult(orderId: 'FCHP-TEST', status: PaymentCallbackStatus::Paid, amountCents: 10000, currency: 'UAH', gatewayPaymentId: 'pay-1');

        app(FestivalPaymentService::class)->completeAttempt($attempt, $callback);
        app(FestivalPaymentService::class)->completeAttempt($attempt->refresh(), $callback);
        $this->assertSame(FestivalChargeStatus::Paid, $charge->refresh()->status);
        $this->assertSame('pay-1', $attempt->refresh()->gateway_payment_id);

        $otherAttempt = FestivalPaymentAttempt::query()->create(['account_id' => $account->id, 'festival_charge_id' => $charge->id, 'provider' => 'monopay', 'order_id' => 'FCHP-WRONG', 'amount_cents' => 10000, 'currency' => 'UAH']);
        $this->expectException(InvalidPaymentCallbackException::class);
        app(FestivalPaymentService::class)->completeAttempt($otherAttempt, new PaymentCallbackResult(orderId: 'FCHP-WRONG', status: PaymentCallbackStatus::Paid, amountCents: 9999, currency: 'UAH'));
    }

    public function test_registration_flow_never_creates_customer_or_event_records(): void
    {
        $customers = Customer::query()->count();
        $events = Event::query()->count();
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);

        $this->actingAs($portalUser, 'festival')->post(route('festival.portal.entries.store', [$account->slug, $edition->slug]), [
            'festival_category_id' => $category->id,
            'participant_ids' => [$participant->id],
            'entry_name' => 'Independent act',
        ])->assertRedirect();

        $this->assertSame($customers, Customer::query()->count());
        $this->assertSame($events, Event::query()->count());
        $this->assertDatabaseHas('festival_entries', ['account_id' => $account->id, 'festival_portal_user_id' => $portalUser->id]);

        $entry = FestivalEntry::query()->where('festival_portal_user_id', $portalUser->id)->sole();
        $createdActivity = FestivalActivityLog::query()->where('festival_entry_id', $entry->id)->where('action', 'entry.created')->sole();
        $this->assertSame($portalUser->id, $createdActivity->actor_portal_user_id);
        $this->assertSame([], $createdActivity->payload);

        $this->put(route('festival.portal.entries.update', [$account->slug, $entry]), [
            'festival_category_id' => $category->id,
            'participant_ids' => [$participant->id],
            'entry_name' => 'Updated independent act',
            'comments' => 'Private changed value',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $updatedActivity = FestivalActivityLog::query()->where('festival_entry_id', $entry->id)->where('action', 'entry.updated')->sole();
        $this->assertEqualsCanonicalizing(['entry_name', 'comments'], $updatedActivity->payload['fields']);
        $this->assertStringNotContainsString('Private changed value', json_encode($updatedActivity->payload, JSON_THROW_ON_ERROR));

        $this->post(route('festival.portal.entries.withdraw', [$account->slug, $entry]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $withdrawnActivity = FestivalActivityLog::query()->where('festival_entry_id', $entry->id)->where('action', 'entry.withdrawn')->sole();
        $this->assertSame($portalUser->id, $withdrawnActivity->actor_portal_user_id);
    }

    public function test_payment_allocation_backfill_creates_one_exact_fact_for_a_legacy_attempt(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'code' => 'FCH-BACKFILL-'.$entry->id,
            'kind' => 'participation',
            'name' => 'Legacy fee',
            'amount_cents' => 12345,
            'currency' => 'UAH',
        ]);
        $attempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'provider' => 'monopay',
            'order_id' => 'FCHP-BACKFILL-'.$entry->id,
            'amount_cents' => 12345,
            'currency' => 'UAH',
        ]);

        $this->assertSame(0, $attempt->allocations()->count());
        $migration = require database_path('migrations/2026_08_22_083438_backfill_festival_payment_attempt_charges.php');
        $migration->up();

        $this->assertDatabaseHas('festival_payment_attempt_charges', [
            'account_id' => $account->id,
            'festival_payment_attempt_id' => $attempt->id,
            'festival_charge_id' => $charge->id,
            'amount_cents' => 12345,
            'currency' => 'UAH',
        ]);
        $this->assertSame(1, $attempt->allocations()->count());
    }

    public function test_cross_tenant_allocation_is_hidden_from_charge_history_and_rejected_by_callbacks(): void
    {
        [$firstAccount, $firstEdition, $firstPortalUser] = $this->festival();
        $firstCategory = FestivalCategory::factory()->for($firstEdition)->create(['account_id' => $firstAccount->id]);
        $firstEntry = FestivalEntry::factory()->for($firstCategory)->create([
            'account_id' => $firstAccount->id,
            'festival_edition_id' => $firstEdition->id,
            'festival_portal_user_id' => $firstPortalUser->id,
        ]);
        $firstCharge = $firstEntry->charges()->create(['account_id' => $firstAccount->id, 'code' => 'FCH-TENANT-FIRST', 'kind' => 'participation', 'name' => 'First fee', 'amount_cents' => 10000, 'currency' => 'UAH']);

        [$secondAccount, $secondEdition, $secondPortalUser] = $this->festival();
        $secondCategory = FestivalCategory::factory()->for($secondEdition)->create(['account_id' => $secondAccount->id]);
        $secondEntry = FestivalEntry::factory()->for($secondCategory)->create([
            'account_id' => $secondAccount->id,
            'festival_edition_id' => $secondEdition->id,
            'festival_portal_user_id' => $secondPortalUser->id,
        ]);
        $secondCharge = $secondEntry->charges()->create(['account_id' => $secondAccount->id, 'code' => 'FCH-TENANT-SECOND', 'kind' => 'participation', 'name' => 'Second fee', 'amount_cents' => 10000, 'currency' => 'UAH']);
        $attempt = FestivalPaymentAttempt::query()->create(['account_id' => $secondAccount->id, 'festival_charge_id' => $secondCharge->id, 'provider' => 'monopay', 'order_id' => 'FCHP-TENANT-CORRUPT', 'amount_cents' => 20000, 'currency' => 'UAH']);
        $attempt->allocations()->create(['account_id' => $secondAccount->id, 'festival_charge_id' => $secondCharge->id, 'amount_cents' => 10000, 'currency' => 'UAH']);
        $attempt->allocations()->create(['account_id' => $secondAccount->id, 'festival_charge_id' => $firstCharge->id, 'amount_cents' => 10000, 'currency' => 'UAH']);

        $this->assertTrue($firstCharge->fresh()->allocatedPaymentAttempts()->isEmpty());
        $this->expectException(InvalidPaymentCallbackException::class);
        app(FestivalPaymentService::class)->completeAttempt($attempt, new PaymentCallbackResult(
            orderId: $attempt->order_id,
            status: PaymentCallbackStatus::Paid,
            amountCents: 20000,
            currency: 'UAH',
        ));
    }

    public function test_portal_separates_festivals_from_applications_and_reuses_the_cover_on_entry_cards(): void
    {
        [$account, $edition, $portalUser] = $this->festival();
        $portalUser->update(['stage_name' => 'Sky Mara']);
        $edition->media()->create([
            'account_id' => $account->id,
            'kind' => 'image',
            'external_url' => 'https://example.test/festival-cover.jpg',
            'alt_text' => 'Festival cover',
            'is_cover' => true,
        ]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);

        $this->actingAs($portalUser, 'festival')->get(route('festival.portal.dashboard', $account->slug))
            ->assertOk()
            ->assertSee(__('app.festivals'))
            ->assertSee(__('app.festival_my_performances'))
            ->assertSee(__('app.festival_portal_team'))
            ->assertSee(__('app.festival_new_application'))
            ->assertSee('src="https://example.test/festival-cover.jpg"', false)
            ->assertSee('alt="Festival cover"', false)
            ->assertDontSee(__('app.notifications'));
        $this->get(route('festival.portal.entries.create', [$account->slug, $edition->slug]))
            ->assertOk()
            ->assertSee('max-w-6xl', false)
            ->assertSee('value="Sky Mara"', false)
            ->assertDontSee('name="profile_phone"', false);

        $this->post(route('festival.portal.entries.store', [$account->slug, $edition->slug]), [
            'festival_category_id' => $category->id,
            'participant_ids' => [$participant->id],
            'entry_name' => 'Sky Mara',
            'act_title' => '',
            'profile_phone' => '+380500000000',
        ])->assertRedirect();

        $entry = FestivalEntry::query()->where('festival_portal_user_id', $portalUser->id)->sole();
        $this->assertNull($entry->act_title);
        $this->assertNotSame('+380500000000', $portalUser->refresh()->phone);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $currentStep = $entry->steps->firstOrFail();
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'festival_entry_step_id' => $currentStep->id,
            'code' => 'FCH-PORTAL-CARD',
            'kind' => 'qualification',
            'name' => 'Portal card fee',
            'amount_cents' => 50000,
            'currency' => 'UAH',
        ]);
        $this->get(route('festival.portal.dashboard', $account->slug))
            ->assertOk()
            ->assertSee(__('app.festival_new_application'))
            ->assertDontSee('Sky Mara');
        $draftApplications = $this->get(route('festival.portal.entries.index', $account->slug));
        $draftApplications
            ->assertOk()
            ->assertSee(__('app.festival_my_performances'))
            ->assertSee('Sky Mara')
            ->assertSee('src="https://example.test/festival-cover.jpg"', false)
            ->assertSee('alt="Festival cover"', false)
            ->assertSee('<span class="crm-status-muted">'.__('app.festival_entry_status_draft').'</span>', false)
            ->assertSee('crm-status-danger', false)
            ->assertSee(__('app.festival_application_payment_unpaid'))
            ->assertDontSee(__('app.festival_new_application'));

        $entry->update(['status' => FestivalEntryStatus::Submitted]);
        $charge->update(['status' => FestivalChargeStatus::Paid, 'paid_at' => now()]);
        $this->get(route('festival.portal.entries.index', $account->slug))
            ->assertOk()
            ->assertSee('<span class="crm-status-scheduled">'.__('app.festival_entry_status_submitted').'</span>', false)
            ->assertSee(__('app.festival_charge_status_paid'));

        $charge->update(['status' => FestivalChargeStatus::Cancelled, 'paid_at' => null]);
        $this->get(route('festival.portal.entries.index', $account->slug))
            ->assertOk()
            ->assertDontSee(__('app.festival_application_payment_unpaid'))
            ->assertDontSee(__('app.festival_charge_status_paid'));
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'age_reference_date' => now()->addMonth()->toDateString(),
            'timezone' => 'Europe/Kyiv',
        ]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        return [$account, $edition, $portalUser];
    }

    /**
     * @param  array<string, mixed>  $categoryAttributes
     * @param  array<string, mixed>  $definitionAttributes
     * @return array{FestivalCategory, FestivalPortalUser, FestivalEntryRequirement}
     */
    private function fileRequirement(array $categoryAttributes, array $definitionAttributes = []): array
    {
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            ...$categoryAttributes,
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $definition = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'type' => FestivalRequirementType::Music->value,
            'allowed_extensions' => [],
            'allowed_mime_types' => [],
            'min_duration_seconds' => null,
            'max_duration_seconds' => null,
            ...$definitionAttributes,
        ]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        $entry->participants()->sync([$participant->id => ['account_id' => $account->id, 'sort_order' => 0]]);
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        $entry->steps()
            ->whereHas('workflowStep', fn ($query) => $query->whereIn('code', ['application', 'participation_payment']))
            ->update(['status' => FestivalEntryStepStatus::Approved->value]);
        $requirement = $entry->requirements->firstWhere('festival_requirement_definition_id', $definition->id);

        $this->assertInstanceOf(FestivalEntryRequirement::class, $requirement);

        return [$category, $portalUser, $requirement];
    }

    private function mockMediaDuration(int|RuntimeException $result): void
    {
        $this->mock(MediaDurationProbe::class, function (MockInterface $mock) use ($result): void {
            $expectation = $mock->shouldReceive('seconds')->once();

            if ($result instanceof RuntimeException) {
                $expectation->andThrow($result);

                return;
            }

            $expectation->andReturn($result);
        });
    }
}
