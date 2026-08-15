<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalEntryStep;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use App\Models\User;
use App\Support\Festivals\FestivalApplicationIndex;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FestivalApplicationIndexTest extends TestCase
{
    use DatabaseTransactions;

    private FestivalApplicationIndex $applicationIndex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationIndex = app(FestivalApplicationIndex::class);
    }

    public function test_current_step_and_mutually_exclusive_work_queues_cover_real_registration_flows(): void
    {
        $fixture = $this->festivalFixture();
        [$firstStep, $secondStep, $thirdStep, $fourthStep] = $fixture['workflow_steps'];
        $secondStep->update(['sort_order' => 20]);
        $thirdStep->update(['sort_order' => 20]);

        [$paymentEntry, $paymentSteps] = $this->createEntry($fixture, 'Passed first, unpaid second', FestivalEntryStatus::UnderReview, [
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
        ]);
        $this->createCharge($paymentEntry, $paymentSteps[1], FestivalChargeStatus::Pending, 290000);
        $this->createCharge($paymentEntry, $paymentSteps[1], FestivalChargeStatus::Paid, 10000);

        [$fourthEntry, $fourthEntrySteps] = $this->createEntry($fixture, 'Paid earlier, fourth form open', FestivalEntryStatus::UnderReview, [
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Draft,
        ]);
        $this->createRequirement(
            $fixture,
            $fourthEntry,
            $fourthEntrySteps[3],
            FestivalRequirementInputType::ShortText,
            FestivalRequirementStatus::Accepted,
        );

        [$reviewEntry] = $this->createEntry($fixture, 'Submitted for review', FestivalEntryStatus::UnderReview, [
            FestivalEntryStepStatus::Submitted,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
        ]);
        [$correctionEntry] = $this->createEntry($fixture, 'Corrections requested', FestivalEntryStatus::ChangesPending, [
            FestivalEntryStepStatus::Submitted,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
        ]);
        [$paidEntry, $paidSteps] = $this->createEntry($fixture, 'Paid but not submitted', FestivalEntryStatus::UnderReview, [
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
        ]);
        $this->createCharge($paidEntry, $paidSteps[1], FestivalChargeStatus::Paid, 290000);

        [$completeEntry] = $this->createEntry($fixture, 'Registration complete', FestivalEntryStatus::Accepted, [
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Approved,
        ]);
        [$rejectedEntry] = $this->createEntry($fixture, 'Rejected application', FestivalEntryStatus::Rejected, [
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
        ]);
        [$withdrawnEntry] = $this->createEntry($fixture, 'Withdrawn application', FestivalEntryStatus::Withdrawn, [
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
            FestivalEntryStepStatus::Draft,
        ]);
        $zeroStepEntry = $this->createStepLessEntry($fixture, 'Accepted without steps', FestivalEntryStatus::Accepted);

        $this->assertSame([$reviewEntry->entry_name], $this->matchingNames($fixture, ['queue' => FestivalApplicationIndex::QueueAwaitingReview]));
        $this->assertSame([$correctionEntry->entry_name], $this->matchingNames($fixture, ['queue' => FestivalApplicationIndex::QueueCorrectionsRequested]));
        $this->assertSame([$paymentEntry->entry_name], $this->matchingNames($fixture, ['queue' => FestivalApplicationIndex::QueuePaymentIncomplete]));
        $this->assertEqualsCanonicalizing([
            $fourthEntry->entry_name,
            $paidEntry->entry_name,
            $zeroStepEntry->entry_name,
        ], $this->matchingNames($fixture, ['queue' => FestivalApplicationIndex::QueueNotSubmitted]));
        $this->assertSame([$completeEntry->entry_name], $this->matchingNames($fixture, ['queue' => FestivalApplicationIndex::QueueComplete]));
        $this->assertEqualsCanonicalizing([
            $rejectedEntry->entry_name,
            $withdrawnEntry->entry_name,
        ], $this->matchingNames($fixture, ['queue' => FestivalApplicationIndex::QueueClosed]));

        $allQueueEntries = collect($this->applicationIndex->queueKeys())
            ->flatMap(fn (string $queue): array => $this->matchingNames($fixture, ['queue' => $queue]));
        $this->assertCount(9, $allQueueEntries);
        $this->assertCount(9, $allQueueEntries->unique());

        $secondStepMatches = $this->matchingNames($fixture, ['current_step' => $secondStep->id]);
        $this->assertEqualsCanonicalizing([$paymentEntry->entry_name, $paidEntry->entry_name], $secondStepMatches);
        $this->assertSame([$paymentEntry->entry_name], $this->matchingNames($fixture, [
            'current_step' => $secondStep->id,
            'payment' => FestivalApplicationIndex::PaymentIncomplete,
        ]));
        $this->assertSame([$fourthEntry->entry_name], $this->matchingNames($fixture, [
            'current_step' => $fourthStep->id,
            'checklist' => FestivalApplicationIndex::ChecklistOpen,
        ]));

        $fourthStep->update(['is_active' => false]);
        $filterData = $this->filterData($fixture, ['current_step' => $fourthStep->id]);
        $this->assertSame((string) $fourthStep->id, $filterData['filters']['current_step']);
        $this->assertTrue($filterData['current_steps']->contains('id', $fourthStep->id));

        $counts = $this->applicationIndex->queueCounts($fixture['edition'], $this->filters($fixture), true);
        $this->assertSame([
            'all' => 9,
            FestivalApplicationIndex::QueueAwaitingReview => 1,
            FestivalApplicationIndex::QueueCorrectionsRequested => 1,
            FestivalApplicationIndex::QueuePaymentIncomplete => 1,
            FestivalApplicationIndex::QueueNotSubmitted => 3,
            FestivalApplicationIndex::QueueComplete => 1,
            FestivalApplicationIndex::QueueClosed => 2,
        ], $counts->all());
        $this->assertSame($paymentSteps[1]->id, $this->indexedEntry($fixture, $paymentEntry)->current_step_id);
        $this->assertSame($fourthEntrySteps[3]->id, $this->indexedEntry($fixture, $fourthEntry)->current_step_id);
        $thirdStep->update(['sort_order' => 15]);
        $this->assertSame($paymentSteps[2]->id, $this->indexedEntry($fixture, $paymentEntry)->current_step_id);
        $this->assertNotSame($firstStep->id, $secondStep->id);
    }

    public function test_checklist_and_payment_filters_match_submission_and_positive_charge_rules(): void
    {
        $fixture = $this->festivalFixture();

        [$missingSubmission, $missingSteps] = $this->createEntry($fixture, 'Accepted status missing response', FestivalEntryStatus::UnderReview, [FestivalEntryStepStatus::Draft]);
        $this->createRequirement($fixture, $missingSubmission, $missingSteps[0], FestivalRequirementInputType::ShortText, FestivalRequirementStatus::Accepted);

        [$falseAgreement, $falseAgreementSteps] = $this->createEntry($fixture, 'False agreement', FestivalEntryStatus::UnderReview, [FestivalEntryStepStatus::Draft]);
        $this->createRequirement($fixture, $falseAgreement, $falseAgreementSteps[0], FestivalRequirementInputType::Agreement, FestivalRequirementStatus::Accepted, ['value' => false]);

        [$waivedField, $waivedSteps] = $this->createEntry($fixture, 'Valid waiver', FestivalEntryStatus::UnderReview, [FestivalEntryStepStatus::Draft]);
        $this->createRequirement($fixture, $waivedField, $waivedSteps[0], FestivalRequirementInputType::ShortText, FestivalRequirementStatus::Waived);

        [$waivedAgreement, $waivedAgreementSteps] = $this->createEntry($fixture, 'Agreement cannot be waived', FestivalEntryStatus::UnderReview, [FestivalEntryStepStatus::Draft]);
        $this->createRequirement($fixture, $waivedAgreement, $waivedAgreementSteps[0], FestivalRequirementInputType::Agreement, FestivalRequirementStatus::Waived);

        [$trueAgreement, $trueAgreementSteps] = $this->createEntry($fixture, 'True agreement', FestivalEntryStatus::UnderReview, [FestivalEntryStepStatus::Draft]);
        $this->createRequirement($fixture, $trueAgreement, $trueAgreementSteps[0], FestivalRequirementInputType::Agreement, FestivalRequirementStatus::Accepted, ['value' => true]);

        [$falseBoolean, $falseBooleanSteps] = $this->createEntry($fixture, 'Valid false boolean', FestivalEntryStatus::UnderReview, [FestivalEntryStepStatus::Draft]);
        $this->createRequirement($fixture, $falseBoolean, $falseBooleanSteps[0], FestivalRequirementInputType::Boolean, FestivalRequirementStatus::Submitted, ['value' => false]);

        [$invalidFile, $invalidFileSteps] = $this->createEntry($fixture, 'File without path', FestivalEntryStatus::UnderReview, [FestivalEntryStepStatus::Draft]);
        $this->createRequirement($fixture, $invalidFile, $invalidFileSteps[0], FestivalRequirementInputType::File, FestivalRequirementStatus::Submitted, null, 'local', '');

        $incompleteChargeEntries = [];
        $paidChargeEntries = [];
        $notRequiredChargeEntries = [];
        foreach (FestivalChargeStatus::cases() as $status) {
            [$entry, $entrySteps] = $this->createEntry($fixture, 'Charge '.$status->value, FestivalEntryStatus::UnderReview, [FestivalEntryStepStatus::Draft]);
            $this->createCharge($entry, $entrySteps[0], $status, 10000);

            if ($status === FestivalChargeStatus::Paid) {
                $paidChargeEntries[] = $entry->entry_name;
            } elseif ($status === FestivalChargeStatus::Cancelled) {
                $notRequiredChargeEntries[] = $entry->entry_name;
            } else {
                $incompleteChargeEntries[] = $entry->entry_name;
            }
        }

        [$zeroCharge, $zeroChargeSteps] = $this->createEntry($fixture, 'Zero pending charge', FestivalEntryStatus::UnderReview, [FestivalEntryStepStatus::Draft]);
        $this->createCharge($zeroCharge, $zeroChargeSteps[0], FestivalChargeStatus::Pending, 0);
        $notRequiredChargeEntries[] = $zeroCharge->entry_name;

        [$multipleCharges, $multipleChargeSteps] = $this->createEntry($fixture, 'Mixed current charges', FestivalEntryStatus::UnderReview, [FestivalEntryStepStatus::Draft]);
        $this->createCharge($multipleCharges, $multipleChargeSteps[0], FestivalChargeStatus::Paid, 10000);
        $this->createCharge($multipleCharges, $multipleChargeSteps[0], FestivalChargeStatus::Failed, 10000);
        $incompleteChargeEntries[] = $multipleCharges->entry_name;

        $this->assertEqualsCanonicalizing([
            $missingSubmission->entry_name,
            $falseAgreement->entry_name,
            $waivedAgreement->entry_name,
            $invalidFile->entry_name,
        ], $this->matchingNames($fixture, ['checklist' => FestivalApplicationIndex::ChecklistOpen]));

        $completeChecklistNames = $this->matchingNames($fixture, ['checklist' => FestivalApplicationIndex::ChecklistComplete]);
        $this->assertContains($waivedField->entry_name, $completeChecklistNames);
        $this->assertContains($trueAgreement->entry_name, $completeChecklistNames);
        $this->assertContains($falseBoolean->entry_name, $completeChecklistNames);
        $this->assertNotContains($falseAgreement->entry_name, $completeChecklistNames);

        $this->assertEqualsCanonicalizing($incompleteChargeEntries, $this->matchingNames($fixture, ['payment' => FestivalApplicationIndex::PaymentIncomplete]));
        $this->assertEqualsCanonicalizing($paidChargeEntries, $this->matchingNames($fixture, ['payment' => FestivalApplicationIndex::PaymentPaid]));

        $notRequiredNames = $this->matchingNames($fixture, ['payment' => FestivalApplicationIndex::PaymentNotRequired]);
        foreach ($notRequiredChargeEntries as $entryName) {
            $this->assertContains($entryName, $notRequiredNames);
        }
        $this->assertNotContains($multipleCharges->entry_name, $notRequiredNames);
        $this->assertSame(1, collect($this->matchingNames($fixture, ['payment' => FestivalApplicationIndex::PaymentIncomplete]))
            ->filter(fn (string $name): bool => $name === $multipleCharges->entry_name)
            ->count());
    }

    public function test_rendered_filters_are_faceted_query_preserving_permission_safe_and_query_stable(): void
    {
        $fixture = $this->festivalFixture();
        [, , , $fourthStep] = $fixture['workflow_steps'];
        [$targetEntry, $targetSteps] = $this->createEntry($fixture, 'Fourth step target', FestivalEntryStatus::UnderReview, [
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Approved,
            FestivalEntryStepStatus::Draft,
        ]);
        $this->createRequirement($fixture, $targetEntry, $targetSteps[3], FestivalRequirementInputType::ShortText, FestivalRequirementStatus::Accepted);

        $parameters = [
            'q' => 'Fourth step',
            'status' => FestivalEntryStatus::UnderReview->value,
            'category' => $fixture['category']->id,
            'queue' => FestivalApplicationIndex::QueueNotSubmitted,
            'current_step' => $fourthStep->id,
            'checklist' => FestivalApplicationIndex::ChecklistOpen,
            'payment' => FestivalApplicationIndex::PaymentNotRequired,
        ];
        $response = $this->actingAs($fixture['owner'])->get(route('dashboard.accounts.festivals.applications', [
            $fixture['account'],
            $fixture['edition'],
            ...$parameters,
        ]));

        $response->assertOk()
            ->assertSee($targetEntry->entry_name)
            ->assertSee('data-queue-pill="not_submitted"', false)
            ->assertSee('min-w-max flex-nowrap', false)
            ->assertSee('-mx-1 overflow-x-auto px-1 pb-2 pt-1', false)
            ->assertSee('<optgroup label="'.$fixture['workflow']->name.'">', false)
            ->assertViewHas('filters', [
                'q' => 'Fourth step',
                'status' => FestivalEntryStatus::UnderReview->value,
                'category' => (string) $fixture['category']->id,
                'queue' => FestivalApplicationIndex::QueueNotSubmitted,
                'current_step' => (string) $fourthStep->id,
                'checklist' => FestivalApplicationIndex::ChecklistOpen,
                'payment' => FestivalApplicationIndex::PaymentNotRequired,
            ])
            ->assertViewHas('queueCounts', fn ($counts): bool => $counts['all'] === 1 && $counts[FestivalApplicationIndex::QueueNotSubmitted] === 1)
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 1);
        $response->assertSee('aria-label="'.__('app.festival_application_work_queues').'"', false)
            ->assertDontSee('<h2 class="text-xl font-semibold">'.__('app.festival_applications_title').'</h2>', false)
            ->assertDontSee('<h3 id="festival-application-work-queues"', false)
            ->assertDontSee('<details class="sm:col-span-2"', false)
            ->assertDontSee(__('app.more_filters'))
            ->assertSee('name="status"', false)
            ->assertSee('name="current_step"', false)
            ->assertSee('name="checklist"', false)
            ->assertSee('name="payment"', false);
        $this->assertSame(7, substr_count($response->getContent(), 'data-queue-pill='));
        $this->assertStringContainsString('status=under_review', $response->getContent());
        $this->assertStringContainsString('current_step='.$fourthStep->id, $response->getContent());
        $this->assertStringContainsString('checklist=open', $response->getContent());
        $this->assertStringContainsString('payment=not_required', $response->getContent());

        FestivalEntry::factory()->count(21)->for($fixture['category'])->state(fn (): array => [
            'account_id' => $fixture['account']->id,
            'festival_edition_id' => $fixture['edition']->id,
            'festival_portal_user_id' => $fixture['portal_user']->id,
            'entry_name' => 'Paged queue '.fake()->unique()->numerify('###'),
            'status' => FestivalEntryStatus::Draft,
        ])->create();
        $paginated = $this->actingAs($fixture['owner'])->get(route('dashboard.accounts.festivals.applications', [
            $fixture['account'],
            $fixture['edition'],
            'q' => 'Paged queue',
            'status' => FestivalEntryStatus::Draft->value,
            'category' => $fixture['category']->id,
            'queue' => FestivalApplicationIndex::QueueNotSubmitted,
        ]));
        $paginated->assertOk()->assertViewHas('entries', fn ($entries): bool => $entries->total() === 21 && $entries->perPage() === 20);
        parse_str((string) parse_url((string) $paginated->viewData('entries')->nextPageUrl(), PHP_URL_QUERY), $nextPageQuery);
        $this->assertSame('Paged queue', $nextPageQuery['q']);
        $this->assertSame(FestivalEntryStatus::Draft->value, $nextPageQuery['status']);
        $this->assertSame((string) $fixture['category']->id, $nextPageQuery['category']);
        $this->assertSame(FestivalApplicationIndex::QueueNotSubmitted, $nextPageQuery['queue']);

        $otherFixture = $this->festivalFixture();
        $invalid = $this->actingAs($fixture['owner'])->get(route('dashboard.accounts.festivals.applications', [
            $fixture['account'],
            $fixture['edition'],
            'status' => 'invalid',
            'category' => $otherFixture['category']->id,
            'queue' => 'invalid',
            'current_step' => $otherFixture['workflow_steps'][0]->id,
            'checklist' => 'invalid',
            'payment' => 'invalid',
        ]));
        $invalid->assertOk()->assertViewHas('filters', [
            'q' => '',
            'status' => '',
            'category' => '',
            'queue' => '',
            'current_step' => '',
            'checklist' => '',
            'payment' => '',
        ]);
        $this->assertSame(22, $invalid->viewData('queueCounts')['all']);

        $registrationStaff = $this->staff($fixture['account'], [StudioPermission::ManageFestivalRegistrations]);
        $financeStaff = $this->staff($fixture['account'], [StudioPermission::ManageFestivalFinance]);
        $scheduleStaff = $this->staff($fixture['account'], [StudioPermission::ManageFestivalSchedule]);
        $registrationSearch = $this->actingAs($registrationStaff)->get(route('dashboard.accounts.festivals.applications', [
            $fixture['account'],
            $fixture['edition'],
            'q' => 'private-applicant@example.test',
        ]));
        $registrationSearch->assertOk()
            ->assertViewHas('workspacePermissions', fn (array $permissions): bool => $permissions['registrations'])
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 22)
            ->assertSee('private-applicant@example.test');
        $this->actingAs($financeStaff)
            ->get(route('dashboard.accounts.festivals.applications', [
                $fixture['account'],
                $fixture['edition'],
                'q' => 'private-applicant@example.test',
            ]))
            ->assertOk()
            ->assertDontSee('· private-applicant@example.test</p>', false)
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 0)
            ->assertViewHas('queueCounts', fn ($counts): bool => $counts['all'] === 0);
        $this->actingAs($scheduleStaff)
            ->get(route('dashboard.accounts.festivals.applications', [$fixture['account'], $fixture['edition']]))
            ->assertForbidden();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->actingAs($fixture['owner'])->get(route('dashboard.accounts.festivals.applications', [
            $fixture['account'],
            $fixture['edition'],
            'q' => 'Fourth step target',
        ]))->assertOk();
        $singleRowQueryCount = count($queries);
        $queries = [];
        $this->actingAs($fixture['owner'])->get(route('dashboard.accounts.festivals.applications', [
            $fixture['account'],
            $fixture['edition'],
            'q' => 'Paged queue',
        ]))->assertOk();
        $pageQueryCount = count($queries);
        $this->assertLessThanOrEqual($singleRowQueryCount + 1, $pageQueryCount);
    }

    /**
     * @return array{
     *     account: Account,
     *     edition: FestivalEdition,
     *     category: FestivalCategory,
     *     portal_user: FestivalPortalUser,
     *     owner: User,
     *     workflow: FestivalWorkflow,
     *     workflow_steps: list<FestivalWorkflowStep>
     * }
     */
    private function festivalFixture(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'timezone' => 'Europe/Kyiv',
        ]);
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id]);
        $workflow = FestivalWorkflow::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Four-step workflow '.Str::random(8),
        ]);
        $workflowSteps = collect(['Application', 'Payment', 'Technical form', 'Summary'])
            ->map(fn (string $title, int $index): FestivalWorkflowStep => FestivalWorkflowStep::factory()->for($workflow, 'workflow')->create([
                'account_id' => $account->id,
                'code' => 'flow-step-'.$index.'-'.Str::lower(Str::random(6)),
                'title' => $title,
                'sort_order' => ($index + 1) * 10,
            ]))
            ->all();
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_direction_id' => $direction->id,
            'festival_workflow_id' => $workflow->id,
            'name' => 'Flow category '.Str::random(8),
        ]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'email' => 'private-applicant@example.test',
        ]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        return [
            'account' => $account,
            'edition' => $edition,
            'category' => $category,
            'portal_user' => $portalUser,
            'owner' => $owner,
            'workflow' => $workflow,
            'workflow_steps' => $workflowSteps,
        ];
    }

    /**
     * @param  array{account: Account, edition: FestivalEdition, category: FestivalCategory, portal_user: FestivalPortalUser, workflow_steps: list<FestivalWorkflowStep>}  $fixture
     * @param  list<FestivalEntryStepStatus>  $stepStatuses
     * @return array{FestivalEntry, list<FestivalEntryStep>}
     */
    private function createEntry(array $fixture, string $name, FestivalEntryStatus $status, array $stepStatuses): array
    {
        $entry = FestivalEntry::factory()->for($fixture['category'])->create([
            'account_id' => $fixture['account']->id,
            'festival_edition_id' => $fixture['edition']->id,
            'festival_portal_user_id' => $fixture['portal_user']->id,
            'entry_name' => $name,
            'status' => $status->value,
            'submitted_at' => now(),
        ]);
        $runtimeSteps = [];
        foreach ($stepStatuses as $index => $stepStatus) {
            $runtimeSteps[] = $entry->steps()->create([
                'account_id' => $fixture['account']->id,
                'festival_workflow_step_id' => $fixture['workflow_steps'][$index]->id,
                'status' => $stepStatus->value,
            ]);
        }

        return [$entry, $runtimeSteps];
    }

    /** @param array{account: Account, edition: FestivalEdition, category: FestivalCategory, portal_user: FestivalPortalUser} $fixture */
    private function createStepLessEntry(array $fixture, string $name, FestivalEntryStatus $status): FestivalEntry
    {
        return FestivalEntry::factory()->for($fixture['category'])->create([
            'account_id' => $fixture['account']->id,
            'festival_edition_id' => $fixture['edition']->id,
            'festival_portal_user_id' => $fixture['portal_user']->id,
            'entry_name' => $name,
            'status' => $status->value,
            'submitted_at' => now(),
        ]);
    }

    private function createCharge(FestivalEntry $entry, FestivalEntryStep $step, FestivalChargeStatus $status, int $amountCents): void
    {
        $entry->charges()->create([
            'account_id' => $entry->account_id,
            'festival_entry_step_id' => $step->id,
            'code' => 'FCH-'.Str::upper(Str::random(12)),
            'kind' => 'participation',
            'name' => 'Participation charge',
            'status' => $status->value,
            'amount_cents' => $amountCents,
            'currency' => 'UAH',
            'paid_at' => $status === FestivalChargeStatus::Paid ? now() : null,
            'cancelled_at' => $status === FestivalChargeStatus::Cancelled ? now() : null,
        ]);
    }

    /**
     * @param  array{account: Account, edition: FestivalEdition, category: FestivalCategory, portal_user: FestivalPortalUser}  $fixture
     * @param  array<string, mixed>|null  $value
     */
    private function createRequirement(
        array $fixture,
        FestivalEntry $entry,
        FestivalEntryStep $step,
        FestivalRequirementInputType $inputType,
        FestivalRequirementStatus $status,
        ?array $value = null,
        ?string $disk = null,
        ?string $path = null,
    ): FestivalEntryRequirement {
        $definition = FestivalRequirementDefinition::factory()->for($fixture['edition'])->create([
            'account_id' => $fixture['account']->id,
            'festival_category_id' => $fixture['category']->id,
            'festival_workflow_step_id' => $step->festival_workflow_step_id,
            'input_type' => $inputType->value,
            'is_required' => true,
            'name' => 'Flow field '.Str::random(8),
        ]);
        $requirement = $entry->requirements()->create([
            'account_id' => $fixture['account']->id,
            'festival_entry_step_id' => $step->id,
            'festival_requirement_definition_id' => $definition->id,
            'subject_key' => 'entry',
            'status' => $status->value,
        ]);

        if ($value !== null || $disk !== null || $path !== null) {
            $requirement->submissions()->create([
                'account_id' => $fixture['account']->id,
                'festival_entry_id' => $entry->id,
                'festival_portal_user_id' => $fixture['portal_user']->id,
                'disk' => $disk,
                'path' => $path,
                'value_json' => $value,
            ]);
        }

        return $requirement;
    }

    /**
     * @param  array{edition: FestivalEdition}  $fixture
     * @param  array<string, mixed>  $parameters
     * @return list<string>
     */
    private function matchingNames(array $fixture, array $parameters): array
    {
        $filters = $this->filters($fixture, $parameters);
        $entriesTable = (new FestivalEntry)->getTable();

        return $this->applicationIndex
            ->query($fixture['edition'], $filters, true)
            ->orderBy($entriesTable.'.id')
            ->pluck($entriesTable.'.entry_name')
            ->all();
    }

    /**
     * @param  array{edition: FestivalEdition}  $fixture
     * @param  array<string, mixed>  $parameters
     * @return array{q: string, status: string, category: string, queue: string, current_step: string, checklist: string, payment: string}
     */
    private function filters(array $fixture, array $parameters = []): array
    {
        return $this->filterData($fixture, $parameters)['filters'];
    }

    /**
     * @param  array{edition: FestivalEdition}  $fixture
     * @param  array<string, mixed>  $parameters
     * @return array{categories: Collection, current_steps: Collection, filters: array{q: string, status: string, category: string, queue: string, current_step: string, checklist: string, payment: string}}
     */
    private function filterData(array $fixture, array $parameters = []): array
    {
        return $this->applicationIndex->filterData(
            Request::create('/applications', 'GET', $parameters),
            $fixture['edition'],
        );
    }

    /** @param array{edition: FestivalEdition} $fixture */
    private function indexedEntry(array $fixture, FestivalEntry $entry): FestivalEntry
    {
        return $this->applicationIndex
            ->query($fixture['edition'], $this->filters($fixture), true)
            ->where((new FestivalEntry)->getTable().'.id', $entry->id)
            ->firstOrFail();
    }

    /** @param list<StudioPermission> $permissions */
    private function staff(Account $account, array $permissions): User
    {
        $staff = User::factory()->create();
        $account->users()->attach($staff->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => array_map(fn (StudioPermission $permission): string => $permission->value, $permissions),
        ]);

        return $staff;
    }
}
