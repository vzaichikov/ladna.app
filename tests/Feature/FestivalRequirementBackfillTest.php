<?php

namespace Tests\Feature;

use App\Actions\Festivals\AttachFestivalEntryRequirements;
use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\StoreFestivalResponse;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FestivalRequirementBackfillTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_dry_runs_then_adds_only_missing_rows_and_reruns_as_a_true_noop(): void
    {
        $fixture = $this->fixture([
            FestivalEntryStatus::Draft,
            FestivalEntryStatus::Submitted,
            FestivalEntryStatus::UnderReview,
            FestivalEntryStatus::Accepted,
        ]);
        $firstEntry = $fixture['entries'][0];
        $firstStep = $fixture['steps'][$firstEntry->id];
        $existing = $firstEntry->requirements()->create([
            'account_id' => $fixture['account']->id,
            'festival_entry_step_id' => $firstStep->id,
            'festival_requirement_definition_id' => $fixture['definitions'][0]->id,
            'subject_key' => 'entry',
            'status' => FestivalRequirementStatus::Accepted->value,
            'reviewed_at' => now()->subDay(),
            'review_notes' => 'Existing answer stays untouched.',
        ]);
        $submission = $existing->submissions()->create([
            'account_id' => $fixture['account']->id,
            'festival_entry_id' => $firstEntry->id,
            'festival_portal_user_id' => $fixture['portal_user']->id,
            'disk' => null,
            'path' => null,
            'original_name' => null,
            'mime_type' => null,
            'size_bytes' => null,
            'value_json' => ['value' => 'Existing response'],
            'status' => 'accepted',
            'reviewed_at' => now()->subDay(),
        ]);
        $charge = $firstEntry->charges()->create([
            'account_id' => $fixture['account']->id,
            'festival_entry_step_id' => $firstStep->id,
            'code' => 'FCH-'.Str::upper(Str::random(12)),
            'kind' => 'participation',
            'name' => 'Existing paid charge',
            'status' => 'paid',
            'amount_cents' => 100,
            'currency' => 'UAH',
            'paid_at' => now()->subDay(),
        ]);
        $charge->paymentAttempts()->create([
            'account_id' => $fixture['account']->id,
            'provider' => 'test',
            'order_id' => 'festival-backfill-'.Str::uuid(),
            'status' => 'paid',
            'amount_cents' => 100,
            'currency' => 'UAH',
            'paid_at' => now()->subDay(),
        ]);
        $before = $this->protectedFingerprint($fixture, $existing, $submission);
        $options = $this->commandOptions($fixture);

        $this->artisan('festivals:backfill-entry-requirements', $options)
            ->expectsOutputToContain('Dry run only. No database changes were made.')
            ->assertSuccessful();
        $this->assertSame(19, app(AttachFestivalEntryRequirements::class)->preview(
            $fixture['account']->id,
            $fixture['edition']->id,
            $fixture['music_step']->id,
            $fixture['definitions']->modelKeys(),
        )['missing_rows']);
        $this->assertSame($before, $this->protectedFingerprint($fixture, $existing, $submission));

        $this->artisan('festivals:backfill-entry-requirements', $options + [
            '--execute' => true,
            '--expected-missing' => 19,
        ])->expectsOutputToContain('Attached 19 missing requirement row(s).')->assertSuccessful();

        $this->assertSame(20, FestivalEntryRequirement::query()
            ->whereIn('festival_entry_id', $fixture['entries']->modelKeys())
            ->whereIn('festival_requirement_definition_id', $fixture['definitions']->modelKeys())
            ->where('subject_key', 'entry')
            ->count());
        $this->assertSame(0, FestivalEntryRequirement::query()
            ->where('festival_entry_id', $fixture['battle_entry']->id)
            ->whereIn('festival_requirement_definition_id', $fixture['definitions']->modelKeys())
            ->count());
        $this->assertSame($before, $this->protectedFingerprint($fixture, $existing, $submission));
        $afterFirstExecution = $this->requirementFingerprint($fixture);

        $this->artisan('festivals:backfill-entry-requirements', $options)
            ->expectsOutputToContain('--expected-missing=0')
            ->assertSuccessful();
        $this->artisan('festivals:backfill-entry-requirements', $options + [
            '--execute' => true,
            '--expected-missing' => 0,
        ])->expectsOutputToContain('Attached 0 missing requirement row(s).')->assertSuccessful();
        $this->assertSame($afterFirstExecution, $this->requirementFingerprint($fixture));
    }

    public function test_exact_scope_and_live_count_guards_fail_before_writing(): void
    {
        $fixture = $this->fixture([FestivalEntryStatus::Draft]);
        $attach = app(AttachFestivalEntryRequirements::class);
        $ids = $fixture['definitions']->modelKeys();

        $this->assertThrows(
            fn () => $attach->preview($fixture['account']->id, $fixture['edition']->id, $fixture['music_step']->id, array_slice($ids, 0, 4)),
            RuntimeException::class,
        );
        $this->assertThrows(
            fn () => $attach->execute($fixture['account']->id, $fixture['edition']->id, $fixture['music_step']->id, $ids, 4),
            RuntimeException::class,
        );
        $this->assertSame(0, FestivalEntryRequirement::query()->where('festival_entry_id', $fixture['entries'][0]->id)->count());

        $fixture['definitions'][4]->forceFill(['is_active' => false])->save();
        $this->assertThrows(
            fn () => $attach->preview($fixture['account']->id, $fixture['edition']->id, $fixture['music_step']->id, $ids),
            RuntimeException::class,
        );
        $fixture['definitions'][4]->forceFill(['is_active' => true, 'subject_scope' => 'participant'])->save();
        $this->assertThrows(
            fn () => $attach->preview($fixture['account']->id, $fixture['edition']->id, $fixture['music_step']->id, $ids),
            RuntimeException::class,
        );
        $fixture['definitions'][4]->forceFill([
            'subject_scope' => 'entry',
            'validation' => ['allow_post_confirmation_edits' => false],
        ])->save();
        $this->assertThrows(
            fn () => $attach->preview($fixture['account']->id, $fixture['edition']->id, $fixture['music_step']->id, $ids),
            RuntimeException::class,
        );
        $fixture['definitions'][4]->forceFill([
            'validation' => [
                'allow_post_confirmation_edits' => true,
                'editable_until_rule' => ['reference' => 'registration_closes_at', 'offset_days' => 0],
            ],
            'festival_workflow_step_id' => $fixture['battle_step']->festival_workflow_step_id,
        ])->save();
        $this->assertThrows(
            fn () => $attach->preview($fixture['account']->id, $fixture['edition']->id, $fixture['music_step']->id, $ids),
            RuntimeException::class,
        );
        $this->assertSame(0, FestivalEntryRequirement::query()->where('festival_entry_id', $fixture['entries'][0]->id)->count());
    }

    public function test_wrong_existing_linkage_fails_closed_and_injected_failure_rolls_back_all_rows(): void
    {
        $fixture = $this->fixture([FestivalEntryStatus::Draft]);
        $entry = $fixture['entries'][0];
        $entry->requirements()->create([
            'account_id' => $fixture['account']->id,
            'festival_entry_step_id' => $fixture['battle_step']->id,
            'festival_requirement_definition_id' => $fixture['definitions'][0]->id,
            'subject_key' => 'entry',
            'status' => FestivalRequirementStatus::Missing->value,
        ]);

        $this->assertThrows(
            fn () => app(AttachFestivalEntryRequirements::class)->execute(
                $fixture['account']->id,
                $fixture['edition']->id,
                $fixture['music_step']->id,
                $fixture['definitions']->modelKeys(),
                4,
            ),
            RuntimeException::class,
        );
        $this->assertSame(1, $entry->requirements()->count());

        $entry->requirements()->delete();
        $insertCount = 0;
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, &$insertCount): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'insert into `festival_entry_requirements`')) {
                $insertCount++;
                if ($insertCount === 2) {
                    throw new RuntimeException('Synthetic Festival backfill rollback probe.');
                }
            }
        });

        try {
            app(AttachFestivalEntryRequirements::class)->execute(
                $fixture['account']->id,
                $fixture['edition']->id,
                $fixture['music_step']->id,
                $fixture['definitions']->modelKeys(),
                5,
            );
            $this->fail('The injected backfill failure must escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic Festival backfill rollback probe.', $exception->getMessage());
        } finally {
            $armed = false;
        }

        $this->assertSame(0, $entry->requirements()->count());
    }

    public function test_missing_optional_field_does_not_block_operational_readiness(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $edition = FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($account))->create(['account_id' => $account->id]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $entry = FestivalEntry::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_category_id' => $category->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::Accepted->value,
        ]);
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        FestivalScheduleSlot::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'festival_entry_id' => $entry->id,
            'festival_category_id' => $category->id,
            'type' => 'performance',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(3),
        ]);
        $optional = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'input_type' => FestivalRequirementInputType::File->value,
            'is_required' => false,
        ]);
        $entry->requirements()->create([
            'account_id' => $account->id,
            'festival_requirement_definition_id' => $optional->id,
            'subject_key' => 'entry',
            'status' => FestivalRequirementStatus::Missing->value,
        ]);

        $this->assertTrue($entry->refresh()->isReady());

        $required = FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'input_type' => FestivalRequirementInputType::LongText->value,
            'is_required' => true,
        ]);
        $entry->requirements()->create([
            'account_id' => $account->id,
            'festival_requirement_definition_id' => $required->id,
            'subject_key' => 'entry',
            'status' => FestivalRequirementStatus::Missing->value,
        ]);

        $this->assertFalse($entry->refresh()->isReady());
    }

    public function test_backfilled_field_uses_existing_accepted_application_edit_flow(): void
    {
        Queue::fake();
        $fixture = $this->fixture([FestivalEntryStatus::Accepted]);
        $entry = $fixture['entries'][0];
        $step = $fixture['steps'][$entry->id];
        $acceptedAt = now()->subDay()->startOfSecond();
        $completedAt = now()->subHours(20)->startOfSecond();
        $entry->forceFill([
            'accepted_at' => $acceptedAt,
            'registration_completed_at' => $completedAt,
        ])->save();
        $step->forceFill([
            'status' => FestivalEntryStepStatus::Approved->value,
            'reviewed_at' => now()->subDay(),
        ])->save();
        app(AttachFestivalEntryRequirements::class)->execute(
            $fixture['account']->id,
            $fixture['edition']->id,
            $fixture['music_step']->id,
            $fixture['definitions']->modelKeys(),
            5,
        );
        $smoke = $entry->requirements()->where('festival_requirement_definition_id', $fixture['definitions'][4]->id)->firstOrFail();

        app(StoreFestivalResponse::class)->execute($smoke, $fixture['portal_user'], 'Light smoke during the final chorus.');

        $this->assertSame(FestivalEntryStatus::ChangesPending, $entry->refresh()->status);
        $this->assertSame(FestivalEntryStepStatus::Submitted, $step->refresh()->status);
        $this->assertSame(FestivalRequirementStatus::Submitted, $smoke->refresh()->status);
        $this->assertTrue($entry->accepted_at->equalTo($acceptedAt));
        $this->assertTrue($entry->registration_completed_at->equalTo($completedAt));
    }

    public function test_future_applications_still_receive_the_five_fields_during_normal_initialization(): void
    {
        $fixture = $this->fixture([FestivalEntryStatus::Draft]);
        app(AttachFestivalEntryRequirements::class)->execute(
            $fixture['account']->id,
            $fixture['edition']->id,
            $fixture['music_step']->id,
            $fixture['definitions']->modelKeys(),
            5,
        );
        $futureEntry = FestivalEntry::factory()->for($fixture['category'])->create([
            'account_id' => $fixture['account']->id,
            'festival_edition_id' => $fixture['edition']->id,
            'festival_portal_user_id' => $fixture['portal_user']->id,
        ]);

        app(InitializeFestivalEntryWorkflow::class)->execute($futureEntry);

        $this->assertSame(5, $futureEntry->requirements()
            ->whereIn('festival_requirement_definition_id', $fixture['definitions']->modelKeys())
            ->count());
    }

    /**
     * @param  list<FestivalEntryStatus>  $statuses
     * @return array<string, mixed>
     */
    private function fixture(array $statuses): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'registration_closes_at' => now()->addMonth(),
        ]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $musicStep = FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create([
            'account_id' => $account->id,
            'code' => 'music',
            'type' => 'form',
            'title' => 'Music',
            'is_active' => true,
        ]);
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
        ]);
        $battleWorkflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $battleWorkflowStep = FestivalWorkflowStep::factory()->for($battleWorkflow, 'workflow')->create([
            'account_id' => $account->id,
            'code' => 'battle',
            'type' => 'form',
            'title' => 'Battle',
        ]);
        $battleCategory = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $battleWorkflow->id,
        ]);
        $definitions = new EloquentCollection(collect([
            ['code' => 'background-video', 'name' => 'Background video', 'input_type' => 'file', 'is_required' => false, 'allowed_extensions' => ['mp4'], 'allowed_mime_types' => ['video/mp4'], 'max_size_kb' => 102400],
            ['code' => 'initial-pole-mode', 'name' => 'Initial pole mode', 'input_type' => 'single_select', 'is_required' => true, 'options' => [['value' => 'static', 'label' => 'Static']]],
            ['code' => 'music-start-cue', 'name' => 'Music start cue', 'input_type' => 'single_select', 'is_required' => true, 'options' => [['value' => 'stage', 'label' => 'On stage']]],
            ['code' => 'lighting-wishes', 'name' => 'Lighting wishes', 'input_type' => 'long_text', 'is_required' => true],
            ['code' => 'smoke-explanation', 'name' => 'Smoke explanation', 'input_type' => 'long_text', 'is_required' => true],
        ])->map(fn (array $attributes): FestivalRequirementDefinition => FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $musicStep->id,
            'festival_category_id' => null,
            'subject_scope' => 'entry',
            'is_active' => true,
            'validation' => [
                'allowed_hosts' => [],
                'allow_post_confirmation_edits' => true,
                'editable_until_rule' => ['reference' => 'registration_closes_at', 'offset_days' => 0],
            ],
            ...$attributes,
        ]))->all());
        $entries = new EloquentCollection(collect($statuses)->map(fn (FestivalEntryStatus $status): FestivalEntry => FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => $status->value,
        ]))->all());
        $steps = $entries->mapWithKeys(function (FestivalEntry $entry) use ($account, $musicStep): array {
            $step = $entry->steps()->create([
                'account_id' => $account->id,
                'festival_workflow_step_id' => $musicStep->id,
                'status' => FestivalEntryStepStatus::Draft->value,
            ]);

            return [$entry->id => $step];
        });
        $battleEntry = FestivalEntry::factory()->for($battleCategory)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => FestivalEntryStatus::Submitted->value,
        ]);
        $battleStep = $battleEntry->steps()->create([
            'account_id' => $account->id,
            'festival_workflow_step_id' => $battleWorkflowStep->id,
            'status' => FestivalEntryStepStatus::Draft->value,
        ]);

        return compact('account', 'edition', 'portalUser', 'category', 'musicStep', 'definitions', 'entries', 'steps', 'battleEntry', 'battleStep') + [
            'portal_user' => $portalUser,
            'music_step' => $musicStep,
            'battle_entry' => $battleEntry,
            'battle_step' => $battleStep,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function commandOptions(array $fixture): array
    {
        return [
            '--account' => $fixture['account']->id,
            '--edition' => $fixture['edition']->id,
            '--workflow-step' => $fixture['music_step']->id,
            '--field' => $fixture['definitions']->modelKeys(),
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function protectedFingerprint(array $fixture, FestivalEntryRequirement $requirement, mixed $submission): string
    {
        $entryIds = $fixture['entries']->modelKeys();
        $chargeIds = DB::table('festival_charges')->whereIn('festival_entry_id', $entryIds)->pluck('id');
        $payload = [
            'entries' => DB::table('festival_entries')->whereIn('id', $entryIds)->orderBy('id')->get()->toArray(),
            'steps' => DB::table('festival_entry_steps')->whereIn('festival_entry_id', $entryIds)->orderBy('id')->get()->toArray(),
            'existing_requirement' => DB::table('festival_entry_requirements')->where('id', $requirement->id)->first(),
            'existing_submission' => DB::table('festival_submissions')->where('id', $submission->id)->first(),
            'charges' => DB::table('festival_charges')->whereIn('id', $chargeIds)->orderBy('id')->get()->toArray(),
            'payment_attempts' => DB::table('festival_payment_attempts')->whereIn('festival_charge_id', $chargeIds)->orderBy('id')->get()->toArray(),
        ];

        return hash('sha256', serialize($payload));
    }

    /** @param array<string, mixed> $fixture */
    private function requirementFingerprint(array $fixture): string
    {
        return hash('sha256', serialize(DB::table('festival_entry_requirements')
            ->whereIn('festival_entry_id', $fixture['entries']->modelKeys())
            ->whereIn('festival_requirement_definition_id', $fixture['definitions']->modelKeys())
            ->orderBy('id')
            ->get()
            ->toArray()));
    }
}
